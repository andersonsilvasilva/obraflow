<?php

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Role;
use App\Modules\Audit\AuditEvent;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Browser;
use Tests\Support\Scenario;

/* Fase 2 — etapa 4b: API de documentos (visibilidade, permissões por documento, URL assinada, vínculos, auditoria). */

function docPdf(string $body = 'x'): UploadedFile
{
    return UploadedFile::fake()->createWithContent('contrato.pdf', "%PDF-1.4\n%DEMO {$body}\n%%EOF\n");
}

beforeEach(function () {
    RateLimiter::clear('login');
    $root = storage_path('framework/testing/disks/documents');
    (new Filesystem)->cleanDirectory($root);
    config(['filesystems.disks.documents.root' => $root]);
    Storage::forgetDisk('documents');

    Scenario::catalog();
    $this->A = Scenario::tenant();
    $this->B = Scenario::tenant();
    $this->adminA = Scenario::member($this->A, 'admin');
    $this->adminB = Scenario::member($this->B, 'admin');
    $this->browser = new Browser($this);
    $this->browser->login($this->adminA->email)->assertOk();
});

function asMember($test, $tenant, string $role = 'member'): array
{
    $user = Scenario::member($tenant, $role);
    $b = new Browser($test);
    $b->login($user->email)->assertOk();

    return [$user, $b];
}

it('cria documento com arquivo, mostra versões sem expor a chave de armazenamento e emite URL assinada que baixa', function () {
    $res = $this->browser->post('/api/v1/documents', ['title' => 'Contrato DEMO', 'file' => docPdf()])->assertCreated();
    $uuid = $res->json('data.uuid');

    $res->assertJsonPath('data.current_version.version_number', 1)->assertJsonPath('data.current_version.mime_type', 'application/pdf');
    expect(json_encode($res->json()))->not->toContain('storage_key')->not->toContain($this->A->uuid);

    $url = $this->browser->post("/api/v1/documents/{$uuid}/versions/1/url")->assertOk()->json('data.url');
    expect($url)->toContain('/files/documents/')->toContain('signature=');
    $this->get($url)->assertOk();
    $this->get(preg_replace('/signature=[^&]+/', 'signature=adulterada', $url))->assertForbidden();
});

it('nova versão vira a corrente; documento arquivado não recebe versão; tipo inválido é recusado', function () {
    $uuid = $this->browser->post('/api/v1/documents', ['title' => 'Planta', 'file' => docPdf('v1')])->json('data.uuid');

    $this->browser->post("/api/v1/documents/{$uuid}/versions", ['file' => docPdf('v2')])->assertCreated()
        ->assertJsonPath('data.current_version.version_number', 2)->assertJsonCount(2, 'data.versions');
    $this->browser->post("/api/v1/documents/{$uuid}/versions", ['file' => UploadedFile::fake()->createWithContent('x.pdf', '<?php')])
        ->assertUnprocessable()->assertJsonPath('error.code', 'invalid_file_type');

    $this->browser->put("/api/v1/documents/{$uuid}", ['status' => 'archived'])->assertOk();
    $this->browser->post("/api/v1/documents/{$uuid}/versions", ['file' => docPdf('v3')])->assertStatus(409);
});

it('documento de outro tenant: 404 em tudo; listagem não vaza', function () {
    $b = new Browser($this);
    $b->login($this->adminB->email)->assertOk();
    $uuidB = $b->post('/api/v1/documents', ['title' => 'Sigiloso B', 'file' => docPdf()])->json('data.uuid');

    $this->browser->get("/api/v1/documents/{$uuidB}")->assertNotFound();
    $this->browser->put("/api/v1/documents/{$uuidB}", ['title' => 'x'])->assertNotFound();
    $this->browser->post("/api/v1/documents/{$uuidB}/versions", ['file' => docPdf()])->assertNotFound();
    $this->browser->post("/api/v1/documents/{$uuidB}/versions/1/url")->assertNotFound();
    $this->browser->put("/api/v1/documents/{$uuidB}/permissions", ['permissions' => []])->assertNotFound();
    $this->browser->get('/api/v1/documents')->assertJsonPath('meta.total', 0);
});

it('privado: member (documents.view) vê e baixa, mas não edita nem cria', function () {
    $uuid = $this->browser->post('/api/v1/documents', ['title' => 'Manual', 'file' => docPdf()])->json('data.uuid');
    [, $m] = asMember($this, $this->A);

    $m->get('/api/v1/documents')->assertJsonPath('meta.total', 1);
    $m->get("/api/v1/documents/{$uuid}")->assertOk()->assertJsonMissingPath('data.permissions');
    $m->post("/api/v1/documents/{$uuid}/versions/1/url")->assertOk();
    $m->put("/api/v1/documents/{$uuid}", ['title' => 'x'])->assertForbidden();
    $m->post("/api/v1/documents/{$uuid}/versions", ['file' => docPdf()])->assertForbidden();
    $m->post('/api/v1/documents', ['title' => 'novo'])->assertForbidden();

    [, $v] = asMember($this, $this->A, 'viewer'); // sem documents.view
    $v->get('/api/v1/documents')->assertForbidden();
});

it('restrito: invisível (404) sem permissão no documento; por usuário ou por papel, conforme a ability', function () {
    $uuid = $this->browser->post('/api/v1/documents', ['title' => 'Folha', 'visibility' => 'restricted', 'file' => docPdf()])->json('data.uuid');
    [$u, $m] = asMember($this, $this->A);

    $m->get('/api/v1/documents')->assertJsonPath('meta.total', 0);
    $m->get("/api/v1/documents/{$uuid}")->assertNotFound();

    // usuário com 'view': vê, mas não baixa
    $this->browser->put("/api/v1/documents/{$uuid}/permissions", ['permissions' => [
        ['subject_type' => 'user', 'subject_id' => $u->id, 'ability' => 'view'],
    ]])->assertOk();
    $m->get("/api/v1/documents/{$uuid}")->assertOk();
    $m->post("/api/v1/documents/{$uuid}/versions/1/url")->assertForbidden();

    // papel 'member' com 'edit': baixa e envia versão, mas não muda a visibilidade (manage)
    $memberRole = Role::query()->forTenant($this->A->id)->where('name', 'member')->value('id');
    $this->browser->put("/api/v1/documents/{$uuid}/permissions", ['permissions' => [
        ['subject_type' => 'role', 'subject_id' => $memberRole, 'ability' => 'edit'],
    ]])->assertOk();
    $m->get('/api/v1/documents')->assertJsonPath('meta.total', 1);
    $m->post("/api/v1/documents/{$uuid}/versions/1/url")->assertOk();
    $m->post("/api/v1/documents/{$uuid}/versions", ['file' => docPdf('v2')])->assertCreated();
    $m->put("/api/v1/documents/{$uuid}", ['visibility' => 'private'])->assertForbidden();
});

it('permissões: sujeito de outro tenant ou sem vínculo ativo é recusado (422)', function () {
    $uuid = $this->browser->post('/api/v1/documents', ['title' => 'X'])->json('data.uuid');
    $roleB = Role::query()->forTenant($this->B->id)->where('name', 'admin')->value('id');

    $this->browser->put("/api/v1/documents/{$uuid}/permissions", ['permissions' => [
        ['subject_type' => 'user', 'subject_id' => $this->adminB->id, 'ability' => 'view'],
        ['subject_type' => 'role', 'subject_id' => $roleB, 'ability' => 'view'],
    ]])->assertUnprocessable()->assertJsonValidationErrors(['permissions.0.subject_id', 'permissions.1.subject_id'], 'error.details');
    $this->browser->put("/api/v1/documents/{$uuid}/permissions", ['permissions' => [
        ['subject_type' => 'project', 'subject_id' => 1, 'ability' => 'view'],
    ]])->assertUnprocessable();
});

it('vínculos: só com cadastro do próprio tenant; filtro por cadastro; desvincular', function () {
    $co = $this->browser->post('/api/v1/companies', ['legal_name' => 'DEMO', 'document_number' => '11222333000181'])->json('data.uuid');
    $b = new Browser($this);
    $b->login($this->adminB->email)->assertOk();
    $coB = $b->post('/api/v1/companies', ['legal_name' => 'DEMO B', 'document_number' => '11222333000181'])->json('data.uuid');
    $uuid = $this->browser->post('/api/v1/documents', ['title' => 'Alvará'])->json('data.uuid');

    $link = $this->browser->post("/api/v1/documents/{$uuid}/links", ['linkable_type' => 'company', 'linkable_key' => $co])->assertCreated()->json('data.id');
    $this->browser->post("/api/v1/documents/{$uuid}/links", ['linkable_type' => 'company', 'linkable_key' => $coB])->assertNotFound();
    $this->browser->post("/api/v1/documents/{$uuid}/links", ['linkable_type' => 'user', 'linkable_key' => '1'])->assertUnprocessable();

    $this->browser->get("/api/v1/documents?linkable_type=company&linkable_key={$co}")->assertJsonPath('meta.total', 1);
    $this->browser->get("/api/v1/documents?linkable_type=company&linkable_key={$coB}")->assertJsonPath('meta.total', 0);
    $this->browser->delete("/api/v1/documents/{$uuid}/links/{$link}")->assertNoContent();
    $this->browser->get("/api/v1/documents?linkable_type=company&linkable_key={$co}")->assertJsonPath('meta.total', 0);
});

it('categorias: código único por tenant, pai do mesmo tenant, sem ciclo', function () {
    $raiz = $this->browser->post('/api/v1/document-categories', ['code' => 'juridico', 'name' => 'Jurídico'])->assertCreated()->json('data.id');
    $filha = $this->browser->post('/api/v1/document-categories', ['code' => 'contratos', 'name' => 'Contratos', 'parent_id' => $raiz])->assertCreated()->json('data.id');

    $this->browser->post('/api/v1/document-categories', ['code' => 'juridico', 'name' => 'dup'])->assertUnprocessable();
    $this->browser->put("/api/v1/document-categories/{$raiz}", ['parent_id' => $filha])->assertUnprocessable()->assertJsonPath('error.code', 'category_cycle');
    $this->browser->put("/api/v1/document-categories/{$raiz}", ['parent_id' => $raiz])->assertUnprocessable();

    $b = new Browser($this);
    $b->login($this->adminB->email)->assertOk();
    $b->post('/api/v1/document-categories', ['code' => 'x', 'name' => 'x', 'parent_id' => $raiz])->assertUnprocessable();
    $b->post('/api/v1/documents', ['title' => 'x', 'category_id' => $raiz])->assertUnprocessable();
});

it('token: documents.view lista e baixa privado, mas não cria', function () {
    $uuid = $this->browser->post('/api/v1/documents', ['title' => 'Manual', 'file' => docPdf()])->json('data.uuid');
    $token = $this->browser->post('/api/v1/tokens', ['name' => 'erp', 'expires_in_days' => 30, 'abilities' => ['documents.view']])->json('token');

    $this->withToken($token)->getJson('/api/v1/documents')->assertOk()->assertJsonPath('meta.total', 1);
    $this->withToken($token)->postJson("/api/v1/documents/{$uuid}/versions/1/url")->assertOk();
    $this->withToken($token)->postJson('/api/v1/documents', ['title' => 'x'])->assertForbidden();
});

it('falha de auditoria na emissão da URL: nenhuma URL é devolvida (falha fechada)', function () {
    $uuid = $this->browser->post('/api/v1/documents', ['title' => 'X', 'file' => docPdf()])->json('data.uuid');
    DB::beforeExecuting(function (string $sql) {
        if (str_starts_with($sql, 'insert into `audit_logs`')) {
            throw new RuntimeException('auditoria fora do ar');
        }
    });

    $res = $this->browser->post("/api/v1/documents/{$uuid}/versions/1/url");
    expect($res->status())->toBe(500)->and(json_encode($res->json()))->not->toContain('signature=');
});

// ---------------------------------------------------------------- cobertura do catálogo da Fase 2
it('TODO evento da Fase 2 (registry.*, document.*) é emitido por alguma operação real', function () {
    $co = $this->browser->post('/api/v1/companies', ['legal_name' => 'DEMO', 'document_number' => '11222333000181'])->json('data.uuid');
    $this->browser->put("/api/v1/companies/{$co}", ['trade_name' => 'D'])->assertOk();                       // registry.updated
    $branch = $this->browser->post("/api/v1/companies/{$co}/branches", ['name' => 'F'])->json('data.id');
    $this->browser->delete("/api/v1/branches/{$branch}")->assertNoContent();                                  // registry.deleted

    $uuid = $this->browser->post('/api/v1/documents', ['title' => 'Doc', 'file' => docPdf()])->json('data.uuid'); // document.created + version_added
    $this->browser->put("/api/v1/documents/{$uuid}", ['title' => 'Doc 2'])->assertOk();                         // document.updated
    $link = $this->browser->post("/api/v1/documents/{$uuid}/links", ['linkable_type' => 'company', 'linkable_key' => $co])->json('data.id');
    $this->browser->delete("/api/v1/documents/{$uuid}/links/{$link}")->assertNoContent();                       // document.unlinked
    $this->browser->put("/api/v1/documents/{$uuid}/permissions", ['permissions' => [
        ['subject_type' => 'user', 'subject_id' => $this->adminA->id, 'ability' => 'view'],
    ]])->assertOk();                                                                                            // document.permissions_changed
    $this->browser->post("/api/v1/documents/{$uuid}/versions/1/url")->assertOk();                               // document.url_issued

    $emitidos = AuditLog::query()->distinct()->pluck('event')->all();
    $faltando = array_values(array_diff(AuditEvent::phase2(), $emitidos));

    expect($faltando)->toBe([], 'eventos da Fase 2 que nenhuma operação emite: '.implode(', ', $faltando))
        ->and(AuditLog::query()->whereIn('event', AuditEvent::phase2())->pluck('tenant_id')->unique()->all())->toBe([$this->A->id])
        ->and(Document::withoutGlobalScopes()->count())->toBe(1);
});
