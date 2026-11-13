<?php

namespace App\Modules\Registry\Services;

use App\Models\Document;
use App\Models\DocumentLink;
use App\Models\DocumentPermission;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Modules\Audit\AuditEvent;
use App\Modules\Audit\AuditLogger;
use App\Modules\Shared\Exceptions\ApiException;
use App\Modules\Tenancy\TenantContext;
use finfo;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Único ponto que escreve documentos, versões, vínculos e permissões (D-P15). Cada escrita e seu evento de
 * auditoria vão na MESMA transação.
 *
 *  - O arquivo vai para o disco privado (config obraflow.documents.disk); a chave NÃO usa o nome original
 *    (evita path traversal e vazamento de informação): {tenant_uuid}/{document_uuid}/{aleatório}.
 *  - Tipo validado pelo CONTEÚDO (finfo), não pela extensão nem pelo Content-Type do cliente.
 *  - Ordem: grava o arquivo → transação (lock do documento, número da versão, versão corrente, auditoria).
 *    Falha no banco remove o arquivo (melhor esforço); falha no disco não grava nada no banco.
 *  - Acesso só por URL assinada de curta duração; a emissão é auditada (falha fechada: sem auditoria, sem URL).
 */
final class DocumentStorage
{
    private const NOT_AUDITED = ['id', 'tenant_id', 'uuid', 'created_at', 'updated_at', 'current_version_id', 'created_by'];

    public function __construct(private readonly TenantContext $context, private readonly AuditLogger $audit) {}

    public function create(array $attributes, User $actor): Document
    {
        return DB::transaction(function () use ($attributes, $actor) {
            $document = new Document($attributes);
            $document->created_by = $actor->getKey();
            $document->save();

            $this->record(AuditEvent::DOCUMENT_CREATED, $document, $actor, null, array_diff_key($document->getAttributes(), array_flip(self::NOT_AUDITED)));

            return $document;
        });
    }

    public function update(Document $document, array $attributes, User $actor): Document
    {
        $this->assertSameTenant($document);

        return DB::transaction(function () use ($document, $attributes, $actor) {
            $document->fill($attributes);
            if (! $document->isDirty()) {
                return $document;
            }
            $changed = array_diff_key($document->getDirty(), array_flip(self::NOT_AUDITED));
            $old = array_intersect_key($document->getOriginal(), $changed);
            $document->save();

            $this->record(AuditEvent::DOCUMENT_UPDATED, $document, $actor, $old, $changed);

            return $document;
        });
    }

    public function storeVersion(Document $document, UploadedFile $file, User $actor): DocumentVersion
    {
        $this->assertSameTenant($document);

        if ($document->status !== 'active') {
            throw ApiException::conflict('document_archived', 'Documento arquivado não recebe novas versões.');
        }

        $maxBytes = config('obraflow.documents.max_kb') * 1024;
        $size = (int) $file->getSize();
        if ($size <= 0 || $size > $maxBytes) {
            throw ApiException::unprocessable('invalid_file_size', 'Arquivo vazio ou acima do limite permitido.');
        }

        // Sempre pelo CONTEÚDO (finfo). UploadedFile::getMimeType() pode cair na extensão (ex.: arquivos de teste).
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        if (! in_array($mime, config('obraflow.documents.allowed_mimes'), true)) {
            throw ApiException::unprocessable('invalid_file_type', 'Tipo de arquivo não permitido.');
        }

        $sha256 = hash_file('sha256', $file->getRealPath());
        $diskName = (string) config('obraflow.documents.disk');
        $key = sprintf('%s/%s/%s', $this->context->tenantOrFail()->uuid, $document->uuid, Str::random(40));

        $stream = fopen($file->getRealPath(), 'rb');
        try {
            $this->disk()->writeStream($key, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        try {
            return DB::transaction(function () use ($document, $file, $actor, $mime, $size, $sha256, $diskName, $key) {
                $locked = Document::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();
                $next = (int) DocumentVersion::query()->where('document_id', $locked->getKey())->max('version_number') + 1;

                $version = new DocumentVersion;
                $version->forceFill([
                    'document_id' => $locked->getKey(),
                    'version_number' => $next,
                    'storage_disk' => $diskName,
                    'storage_key' => $key,
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'mime_type' => $mime,
                    'size_bytes' => $size,
                    'sha256' => $sha256,
                    'uploaded_by' => $actor->getKey(),
                ])->save();

                $locked->current_version_id = $version->getKey();
                $locked->save();
                $document->setRawAttributes($locked->getAttributes(), true);

                $this->record(AuditEvent::DOCUMENT_VERSION_ADDED, $locked, $actor, null, [
                    'version_number' => $next, 'original_name' => $version->original_name, 'mime_type' => $mime,
                    'size_bytes' => $size, 'sha256' => $sha256,
                ]);

                return $version;
            });
        } catch (Throwable $e) {
            try {
                $this->disk()->delete($key);
            } catch (Throwable) {
                // arquivo órfão é preferível a mascarar o erro original
            }
            throw $e;
        }
    }

    /** URL assinada de curta duração (padrão 5 min). A emissão é auditada ANTES de devolver a URL. */
    public function temporaryUrl(DocumentVersion $version, User $actor): string
    {
        $this->assertSameTenant($version);

        $minutes = (int) config('obraflow.documents.signed_url_minutes');
        $this->audit->record(AuditEvent::DOCUMENT_URL_ISSUED, tenantId: $this->context->idOrFail(), userId: $actor->getKey(),
            entityType: 'document', entityId: $version->document_id,
            metadata: ['version_number' => $version->version_number, 'expires_in_minutes' => $minutes]);

        return Storage::disk($version->storage_disk)->temporaryUrl($version->storage_key, now()->addMinutes($minutes));
    }

    /**
     * Vincula o documento a um cadastro. document_links é polimórfica (sem FK do dono), então AQUI se garante
     * que o dono é de um tipo permitido, existe, não está excluído e pertence ao tenant ativo.
     */
    public function link(Document $document, Model $owner, User $actor): DocumentLink
    {
        $this->assertSameTenant($document);

        $type = $owner->getMorphClass();
        if (! in_array($type, DocumentLink::LINKABLE_TYPES, true) || ! $owner->exists) {
            throw ApiException::unprocessable('invalid_link_target', 'Destino de vínculo inválido.');
        }
        $this->assertSameTenant($owner);
        if (method_exists($owner, 'trashed') && $owner->trashed()) {
            throw ApiException::notFound();
        }

        $attributes = ['document_id' => $document->getKey(), 'linkable_type' => $type, 'linkable_id' => $owner->getKey()];

        $existing = DocumentLink::query()->where($attributes)->first();
        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($attributes, $document, $actor) {
            $link = new DocumentLink;
            $link->forceFill($attributes)->save();
            $this->record(AuditEvent::DOCUMENT_LINKED, $document, $actor, null,
                ['linkable_type' => $attributes['linkable_type'], 'linkable_id' => $attributes['linkable_id']]);

            return $link;
        });
    }

    public function unlink(Document $document, DocumentLink $link, User $actor): void
    {
        $this->assertSameTenant($document);
        if ($link->document_id !== $document->getKey()) {
            throw ApiException::notFound();
        }

        DB::transaction(function () use ($document, $link, $actor) {
            $link->delete();
            $this->record(AuditEvent::DOCUMENT_UNLINKED, $document, $actor,
                ['linkable_type' => $link->linkable_type, 'linkable_id' => $link->linkable_id], null);
        });
    }

    /**
     * Substitui as permissões do documento. Os sujeitos já foram validados no tenant ativo (usuário com vínculo,
     * papel do tenant). @param  list<array{subject_type: string, subject_id: int, ability: string}>  $entries
     */
    public function syncPermissions(Document $document, array $entries, User $actor): void
    {
        $this->assertSameTenant($document);

        DB::transaction(function () use ($document, $entries, $actor) {
            $normalize = fn ($rows) => collect($rows)->map(fn ($r) => [
                'subject_type' => $r['subject_type'], 'subject_id' => (int) $r['subject_id'], 'ability' => $r['ability'],
            ])->unique(fn ($r) => implode(':', $r))->sortBy(fn ($r) => implode(':', $r))->values()->all();

            $old = $normalize($document->permissions()->get(['subject_type', 'subject_id', 'ability'])->toArray());
            $new = $normalize($entries);
            if ($old === $new) {
                return;
            }

            $document->permissions()->delete();
            foreach ($new as $row) {
                $perm = new DocumentPermission($row);
                $perm->document_id = $document->getKey();
                $perm->save();
            }

            $this->record(AuditEvent::DOCUMENT_PERMISSIONS_CHANGED, $document, $actor, ['permissions' => $old], ['permissions' => $new]);
        });
    }

    private function record(string $event, Document $document, User $actor, ?array $old, ?array $new): void
    {
        $this->audit->record($event, tenantId: $this->context->idOrFail(), userId: $actor->getKey(),
            entityType: 'document', entityId: $document->getKey(), old: $old, new: $new);
    }

    private function assertSameTenant(Model $model): void
    {
        // 404 idêntico a "não existe" (SECURITY.md §4): não revela que o registro é de outro tenant.
        if ((int) $model->getAttribute('tenant_id') !== $this->context->idOrFail()) {
            throw ApiException::notFound();
        }
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('obraflow.documents.disk'));
    }
}
