<?php

namespace App\Modules\Registry\Services;

use App\Models\Document;
use App\Models\DocumentLink;
use App\Models\DocumentVersion;
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
 * Único ponto que cria documentos, versões e vínculos (D-P15).
 *
 *  - O arquivo vai para o disco privado (config obraflow.documents.disk); a chave NÃO usa o nome original
 *    (evita path traversal e vazamento de informação): {tenant_uuid}/{document_uuid}/v{n}-{aleatório}.
 *  - Tipo validado pelo CONTEÚDO (finfo), não pela extensão nem pelo Content-Type do cliente.
 *  - Ordem: grava o arquivo → transação (lock do documento, número da versão, versão corrente).
 *    Falha no banco remove o arquivo (melhor esforço); falha no disco não grava nada no banco.
 *  - Acesso só por URL assinada de curta duração; nunca URL pública permanente.
 *
 * Auditoria dos eventos de documento entra com a API (etapa 4).
 */
final class DocumentStorage
{
    public function __construct(private readonly TenantContext $context) {}

    public function create(array $attributes, int $creatorUserId): Document
    {
        $document = new Document($attributes);
        $document->created_by = $creatorUserId;
        $document->save();

        return $document;
    }

    public function storeVersion(Document $document, UploadedFile $file, int $uploaderUserId): DocumentVersion
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
            return DB::transaction(function () use ($document, $file, $uploaderUserId, $mime, $size, $sha256, $diskName, $key) {
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
                    'uploaded_by' => $uploaderUserId,
                ])->save();

                $locked->current_version_id = $version->getKey();
                $locked->save();
                $document->setRawAttributes($locked->getAttributes(), true);

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

    /** URL assinada de curta duração (padrão 5 min). */
    public function temporaryUrl(DocumentVersion $version): string
    {
        $this->assertSameTenant($version);

        return Storage::disk($version->storage_disk)->temporaryUrl(
            $version->storage_key,
            now()->addMinutes((int) config('obraflow.documents.signed_url_minutes')),
            ['ResponseContentDisposition' => 'attachment; filename="'.addcslashes($version->original_name, '"\\').'"'],
        );
    }

    /**
     * Vincula o documento a um cadastro. document_links é polimórfica (sem FK do dono), então AQUI se garante
     * que o dono é de um tipo permitido, existe, não está excluído e pertence ao tenant ativo.
     */
    public function link(Document $document, Model $owner): DocumentLink
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

        $link = new DocumentLink;
        $link->forceFill($attributes)->save();

        return $link;
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
