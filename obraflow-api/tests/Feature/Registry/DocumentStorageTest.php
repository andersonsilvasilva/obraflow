<?php

use App\Models\Company;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Modules\Registry\Services\DocumentStorage;
use App\Modules\Shared\Exceptions\ApiException;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Scenario;

/* Fase 2 — etapa 3: serviço de documentos (disco privado, versões, URL assinada, vínculos). */

function pdf(string $name = 'contrato.pdf', string $body = 'conteudo'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%DEMO {$body}\n%%EOF\n");
}

beforeEach(function () {
    // Disco REAL (não Storage::fake, que troca o gerador de URL assinada) em pasta de teste descartável.
    $root = storage_path('framework/testing/disks/documents');
    (new Filesystem)->cleanDirectory($root);
    config(['filesystems.disks.documents.root' => $root]);
    Storage::forgetDisk('documents');
    Scenario::catalog();
    $this->A = Scenario::tenant();
    $this->B = Scenario::tenant();
    $this->uA = Scenario::member($this->A);
    $this->uB = Scenario::member($this->B);
    $this->ctx = app(TenantContext::class);
    $this->svc = app(DocumentStorage::class);
});

function inTenant($tenant, callable $fn): mixed
{
    return app(TenantContext::class)->run($tenant, $fn);
}

it('grava a versão no disco privado com sha256, chave sem o nome original e versão corrente', function () {
    [$doc, $v] = inTenant($this->A, function () {
        $doc = $this->svc->create(['title' => 'Contrato DEMO'], $this->uA);

        return [$doc, $this->svc->storeVersion($doc, pdf(), $this->uA)];
    });

    expect($v->version_number)->toBe(1)
        ->and($v->mime_type)->toBe('application/pdf')
        ->and($v->original_name)->toBe('contrato.pdf')
        ->and($v->storage_key)->not->toContain('contrato')
        ->and($v->storage_key)->toStartWith($this->A->uuid.'/'.$doc->uuid.'/')
        ->and($v->sha256)->toBe(hash('sha256', Storage::disk('documents')->get($v->storage_key)))
        ->and($doc->fresh()->current_version_id)->toBe($v->id);
    Storage::disk('documents')->assertExists($v->storage_key);
});

it('versões são numeradas em sequência e a corrente acompanha a última', function () {
    $v2 = inTenant($this->A, function () {
        $doc = $this->svc->create(['title' => 'Planta'], $this->uA);
        $this->svc->storeVersion($doc, pdf(body: 'v1'), $this->uA);

        return $this->svc->storeVersion($doc, pdf(body: 'v2'), $this->uA);
    });

    expect($v2->version_number)->toBe(2)
        ->and(Document::withoutGlobalScopes()->find($v2->document_id)->current_version_id)->toBe($v2->id);
});

it('recusa tipo pelo conteúdo (extensão .pdf com conteúdo de texto/script) e arquivo acima do limite', function () {
    inTenant($this->A, function () {
        $doc = $this->svc->create(['title' => 'X'], $this->uA);

        expect(fn () => $this->svc->storeVersion($doc, UploadedFile::fake()->createWithContent('falso.pdf', '<?php echo 1;'), $this->uA))
            ->toThrow(ApiException::class, 'Tipo de arquivo não permitido.');

        config(['obraflow.documents.max_kb' => 1]);
        expect(fn () => $this->svc->storeVersion($doc, pdf(body: str_repeat('a', 2048)), $this->uA))
            ->toThrow(ApiException::class, 'Arquivo vazio ou acima do limite permitido.');
    });

    expect(Storage::disk('documents')->allFiles())->toBeEmpty()
        ->and(DB::table('document_versions')->count())->toBe(0);
});

it('documento arquivado não recebe nova versão', function () {
    inTenant($this->A, function () {
        $doc = $this->svc->create(['title' => 'X', 'status' => 'archived'], $this->uA);

        expect(fn () => $this->svc->storeVersion($doc, pdf(), $this->uA))->toThrow(ApiException::class);
    });
});

it('documento de outro tenant: versão e URL respondem 404 (não revela existência)', function () {
    [$doc, $v] = inTenant($this->A, function () {
        $doc = $this->svc->create(['title' => 'Sigiloso'], $this->uA);

        return [$doc, $this->svc->storeVersion($doc, pdf(), $this->uA)];
    });

    inTenant($this->B, function () use ($doc, $v) {
        expect(fn () => $this->svc->storeVersion($doc, pdf(), $this->uB))->toThrow(ApiException::class, 'Recurso não encontrado.')
            ->and(fn () => $this->svc->temporaryUrl($v, $this->uB))->toThrow(ApiException::class, 'Recurso não encontrado.');
    });
});

it('falha no banco remove o arquivo já gravado (sem órfão) e não deixa versão', function () {
    inTenant($this->A, function () {
        $doc = $this->svc->create(['title' => 'X'], $this->uA);
        DB::beforeExecuting(function (string $sql) {
            if (str_starts_with($sql, 'insert into `document_versions`')) {
                throw new RuntimeException('falha simulada');
            }
        });

        expect(fn () => $this->svc->storeVersion($doc, pdf(), $this->uA))->toThrow(RuntimeException::class, 'falha simulada');
    });

    expect(Storage::disk('documents')->allFiles())->toBeEmpty()
        ->and(DB::table('document_versions')->count())->toBe(0);
});

it('URL temporária é assinada, expira e o arquivo não tem URL pública', function () {
    $v = inTenant($this->A, function () {
        $doc = $this->svc->create(['title' => 'X'], $this->uA);

        return $this->svc->temporaryUrl($this->svc->storeVersion($doc, pdf(), $this->uA), $this->uA);
    });

    expect($v)->toContain('signature=')->toContain('expires=');
    $this->get($v)->assertOk();

    $this->travel(6)->minutes();
    $this->get($v)->assertForbidden();
});

it('vincula a cadastro do próprio tenant; recusa dono de outro tenant, excluído ou de tipo não permitido', function () {
    $coB = inTenant($this->B, fn () => Company::create(['legal_name' => 'DEMO B', 'document_number' => '99.888.777/0001-55']));

    inTenant($this->A, function () use ($coB) {
        $doc = $this->svc->create(['title' => 'X'], $this->uA);
        $co = Company::create(['legal_name' => 'DEMO A', 'document_number' => '11.222.333/0001-81']);
        $cu = Customer::create(['company_id' => $co->id, 'type' => 'person', 'name' => 'Cliente DEMO']);

        $link = $this->svc->link($doc, $cu, $this->uA);
        expect($link->linkable_type)->toBe('customer')
            ->and($this->svc->link($doc, $cu, $this->uA)->id)->toBe($link->id) // idempotente
            ->and(fn () => $this->svc->link($doc, $coB, $this->uA))->toThrow(ApiException::class, 'Recurso não encontrado.')
            ->and(fn () => $this->svc->link($doc, $this->uA, $this->uA))->toThrow(ApiException::class, 'Destino de vínculo inválido.');

        $cu->delete(); // soft delete
        expect(fn () => $this->svc->link($doc, $cu, $this->uA))->toThrow(ApiException::class, 'Recurso não encontrado.');
    });
});

it('versão é append-only pela aplicação', function () {
    inTenant($this->A, function () {
        $doc = $this->svc->create(['title' => 'X'], $this->uA);
        $v = $this->svc->storeVersion($doc, pdf(), $this->uA);

        expect(fn () => $v->update(['original_name' => 'outro.pdf']))->toThrow(MassAssignmentException::class)
            ->and(fn () => $v->forceFill(['original_name' => 'outro.pdf'])->save())->toThrow(LogicException::class, 'append-only')
            ->and(fn () => $v->delete())->toThrow(LogicException::class)
            ->and(fn () => DocumentVersion::query()->whereKey($v->id)->update(['original_name' => 'y']))->toThrow(LogicException::class, 'document_versions é append-only')
            ->and(fn () => DocumentVersion::query()->whereKey($v->id)->delete())->toThrow(LogicException::class);
    });
});
