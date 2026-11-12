<?php
/**
 * Validação do isolamento multi-tenant da Fase 1 contra uma instância MySQL 8.0.30 DESCARTÁVEL.
 * Parte 1: regras no banco (FK composta, CHECK, UNIQUE, colunas geradas, privilégios, fuso, plugin de auth).
 * Parte 2: Spatie Permission (Teams) e Sanctum REAIS sobre o mesmo esquema.
 */
declare(strict_types=1);

const HOST = '127.0.0.1';
const PORT = 3399;
const DB = 'obraflow_sandbox';

$pass = 0; $fail = 0; $failures = [];

function report(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $failures;
    if ($ok) { $pass++; echo "  [PASS] $name\n"; }
    else { $fail++; $failures[] = $name; echo "  [FAIL] $name" . ($detail !== '' ? "  -> $detail" : '') . "\n"; }
}
function section(string $t): void { echo "\n== $t ==\n"; }

function errno(Throwable $e): ?int
{
    for ($x = $e; $x; $x = $x->getPrevious()) {
        if ($x instanceof PDOException && isset($x->errorInfo[1])) return (int) $x->errorInfo[1];
    }
    return null;
}
/** Espera que $fn lance erro de banco com um dos códigos. */
function expectDbError(string $name, callable $fn, array $codes): void
{
    try { $fn(); report($name, false, 'nenhum erro (operação foi ACEITA)'); }
    catch (Throwable $e) {
        $c = errno($e);
        report($name, in_array($c, $codes, true), "errno=" . var_export($c, true) . ' ' . substr($e->getMessage(), 0, 120));
    }
}
function expectOk(string $name, callable $fn): void
{
    try { $r = $fn(); report($name, $r !== false); }
    catch (Throwable $e) { report($name, false, 'erro inesperado: ' . substr($e->getMessage(), 0, 160)); }
}

function uuid(): string
{
    $d = random_bytes(16); $d[6] = chr((ord($d[6]) & 0x0f) | 0x40); $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

function connect(string $user = 'root', string $pass = '', ?string $db = DB): PDO
{
    $dsn = 'mysql:host=' . HOST . ';port=' . PORT . ';charset=utf8mb4' . ($db ? ";dbname=$db" : '');
    return new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

$pdo = connect();
function q(string $sql, array $p = []): PDOStatement { global $pdo; $s = $pdo->prepare($sql); $s->execute($p); return $s; }
function newId(): int { global $pdo; return (int) $pdo->lastInsertId(); }
function mkTenant(string $slug, string $status = 'active'): int { q('INSERT INTO tenants (uuid,name,slug,status) VALUES (?,?,?,?)', [uuid(), "T $slug", $slug, $status]); return newId(); }
function mkUser(string $email): int { q('INSERT INTO users (uuid,name,email,password) VALUES (?,?,?,?)', [uuid(), "U $email", $email, 'x']); return newId(); }
function mkMember(int $t, int $u, string $s = 'active'): int { q('INSERT INTO tenant_users (tenant_id,user_id,membership_status) VALUES (?,?,?)', [$t, $u, $s]); return newId(); }
function mkRole(int $t, string $name): int { q("INSERT INTO roles (tenant_id,name,guard_name) VALUES (?,?,'web')", [$t, $name]); return newId(); }
function assign(int $tenant, int $role, int $user, string $type = 'user'): void { q('INSERT INTO model_has_roles (role_id,model_type,model_id,tenant_id) VALUES (?,?,?,?)', [$role, $type, $user, $tenant]); }

// ---------------------------------------------------------------- PARTE 1
section('0. Esquema');
$tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema='" . DB . "'")->fetchAll(PDO::FETCH_COLUMN);
report('15 tabelas criadas', count($tables) === 15, (string) count($tables));
$noPk = $pdo->query("SELECT t.table_name FROM information_schema.tables t LEFT JOIN information_schema.table_constraints c ON c.table_schema=t.table_schema AND c.table_name=t.table_name AND c.constraint_type='PRIMARY KEY' WHERE t.table_schema='" . DB . "' AND c.constraint_name IS NULL")->fetchAll(PDO::FETCH_COLUMN);
report('todas as tabelas têm PRIMARY KEY (compatível com sql_require_primary_key=1)', $noPk === [], implode(',', $noPk));
$nullableTenant = $pdo->query("SELECT is_nullable FROM information_schema.columns WHERE table_schema='" . DB . "' AND table_name='roles' AND column_name='tenant_id'")->fetchColumn();
report('roles.tenant_id é NOT NULL', $nullableTenant === 'NO');
$cascade = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema='" . DB . "' AND (delete_rule<>'RESTRICT' OR update_rule<>'RESTRICT')")->fetchColumn();
report('todas as FKs são RESTRICT (nenhuma CASCADE/SET NULL)', $cascade === 0, (string) $cascade);

section('1. Dados base');
$A = mkTenant('acme'); $B = mkTenant('beta');
$u1 = mkUser('ana@acme.test');     // só em A
$u2 = mkUser('bia@both.test');     // em A e B
$u3 = mkUser('caio@none.test');    // sem vínculo
mkMember($A, $u1); mkMember($A, $u2); mkMember($B, $u2);
$roleA = mkRole($A, 'admin'); $roleB = mkRole($B, 'admin'); $viewerB = mkRole($B, 'viewer');
report('tenants, usuários, vínculos e papéis criados', true);

section('2. Papéis × tenant × vínculo (model_has_roles)');
expectOk('atribuir papel de A a usuário vinculado a A', fn () => assign($A, $roleA, $u1));
expectDbError('papel do tenant B no vínculo do tenant A é RECUSADO (FK composta role)', fn () => assign($A, $roleB, $u1), [1452]);
expectDbError('papel de A atribuído a usuário SEM vínculo é RECUSADO (FK composta membro)', fn () => assign($A, $roleA, $u3), [1452]);
expectDbError('papel de A atribuído em B a usuário que só é de A é RECUSADO', fn () => assign($B, $roleA, $u1), [1452]);
expectOk('mesma pessoa admin em A e viewer em B (usuário global em dois tenants)', function () use ($A, $B, $roleA, $viewerB, $u2) { assign($A, $roleA, $u2); assign($B, $viewerB, $u2); return true; });
expectDbError('model_type diferente de "user" é RECUSADO (CHECK)', fn () => assign($A, $roleA, $u2, 'App\\Models\\User'), [3819]);
expectDbError('papel sem tenant (NULL) é RECUSADO', fn () => q("INSERT INTO roles (tenant_id,name,guard_name) VALUES (NULL,'global','web')"), [1048]);
expectDbError('papel duplicado no MESMO tenant é RECUSADO', fn () => mkRole($A, 'admin'), [1062]);
expectOk('mesmo nome de papel em OUTRO tenant é permitido', fn () => mkRole($B, 'admin_2') > 0 && mkRole($A, 'viewer') > 0);
expectDbError('excluir papel que tem atribuição é RECUSADO (RESTRICT)', fn () => q('DELETE FROM roles WHERE id=?', [$roleA]), [1451]);
expectDbError('excluir vínculo referenciado por atribuição é RECUSADO', fn () => q('DELETE FROM tenant_users WHERE tenant_id=? AND user_id=?', [$A, $u1]), [1451]);
expectDbError('excluir tenant com papéis é RECUSADO', fn () => q('DELETE FROM tenants WHERE id=?', [$A]), [1451]);
q("INSERT INTO permissions (name,guard_name,module) VALUES ('members.invite','web','members')"); $perm = newId();
expectOk('vincular permissão global a papel do tenant', fn () => q('INSERT INTO role_has_permissions (permission_id,role_id) VALUES (?,?)', [$perm, $roleA]));
expectDbError('excluir permissão em uso é RECUSADO', fn () => q('DELETE FROM permissions WHERE id=?', [$perm]), [1451]);
expectDbError('permissão direta a usuário SEM vínculo é RECUSADA', fn () => q("INSERT INTO model_has_permissions (permission_id,model_type,model_id,tenant_id) VALUES (?,?,?,?)", [$perm, 'user', $u3, $A]), [1452]);
expectOk('permissão direta a usuário COM vínculo é aceita pelo banco', fn () => q("INSERT INTO model_has_permissions (permission_id,model_type,model_id,tenant_id) VALUES (?,?,?,?)", [$perm, 'user', $u1, $A]));

section('3. Vínculos, usuários e tenants');
expectDbError('vínculo duplicado (tenant,user) é RECUSADO', fn () => mkMember($A, $u1), [1062]);
expectDbError('vínculo com status inválido é RECUSADO', fn () => mkMember($B, $u1, 'invited'), [3819]);
expectDbError('vínculo com usuário inexistente é RECUSADO', fn () => mkMember($A, 999999), [1452]);
expectDbError('e-mail duplicado ignorando CAIXA é RECUSADO', fn () => mkUser('ANA@ACME.TEST'), [1062]);
expectOk('e-mails que diferem só por ACENTO coexistem (collation as_ci)', fn () => mkUser('jose@x.test') > 0 && mkUser('josé@x.test') > 0);
expectDbError('status de usuário inválido é RECUSADO', fn () => q("INSERT INTO users (uuid,name,email,password,status) VALUES (?,?,?,?,'zzz')", [uuid(), 'n', 'st@x.test', 'x']), [3819]);
expectDbError('slug de tenant duplicado é RECUSADO', fn () => mkTenant('acme'), [1062]);
expectDbError('status de tenant inválido é RECUSADO', fn () => mkTenant('weird', 'deleted'), [3819]);

section('4. Domínios e configurações');
expectOk('um domínio primário por tenant', fn () => q('INSERT INTO tenant_domains (tenant_id,domain,is_primary) VALUES (?,?,1)', [$A, 'acme.test']));
expectDbError('segundo domínio primário no mesmo tenant é RECUSADO', fn () => q('INSERT INTO tenant_domains (tenant_id,domain,is_primary) VALUES (?,?,1)', [$A, 'acme2.test']), [1062]);
expectOk('vários domínios NÃO primários no mesmo tenant', fn () => q('INSERT INTO tenant_domains (tenant_id,domain,is_primary) VALUES (?,?,0)', [$A, 'a1.test']) && q('INSERT INTO tenant_domains (tenant_id,domain,is_primary) VALUES (?,?,0)', [$A, 'a2.test']));
expectOk('outro tenant pode ter o seu primário', fn () => q('INSERT INTO tenant_domains (tenant_id,domain,is_primary) VALUES (?,?,1)', [$B, 'beta.test']));
expectDbError('mesmo domínio em dois tenants é RECUSADO', fn () => q('INSERT INTO tenant_domains (tenant_id,domain,is_primary) VALUES (?,?,0)', [$B, 'a1.test']), [1062]);
expectOk('configuração por tenant', fn () => q('INSERT INTO tenant_settings (tenant_id,setting_key,setting_value) VALUES (?,?,?)', [$A, 'currency', '"BRL"']));
expectDbError('configuração duplicada no tenant é RECUSADA', fn () => q('INSERT INTO tenant_settings (tenant_id,setting_key,setting_value) VALUES (?,?,?)', [$A, 'currency', '"USD"']), [1062]);
expectOk('mesma chave em outro tenant é permitida', fn () => q('INSERT INTO tenant_settings (tenant_id,setting_key,setting_value) VALUES (?,?,?)', [$B, 'currency', '"BRL"']));

section('5. Tokens (Sanctum + tenant)');
function mkToken(?int $tenant, int $user, string $type = 'user'): void
{ q('INSERT INTO personal_access_tokens (tokenable_type,tokenable_id,name,token,tenant_id) VALUES (?,?,?,?,?)', [$type, $user, 't', bin2hex(random_bytes(32)), $tenant]); }
expectOk('token COM tenant para usuário vinculado', fn () => mkToken($A, $u1));
expectDbError('token com tenant para usuário SEM vínculo é RECUSADO', fn () => mkToken($A, $u3), [1452]);
expectDbError('token do tenant B para usuário que só é de A é RECUSADO', fn () => mkToken($B, $u1), [1452]);
expectOk('token SEM tenant é aceito pelo banco (regra de negócio o barra nas rotas)', fn () => mkToken(null, $u3));
expectDbError('tokenable_type diferente de "user" é RECUSADO', fn () => mkToken($A, $u1, 'App\\Models\\User'), [3819]);
expectDbError('token com tenant inexistente é RECUSADO', fn () => mkToken(999999, $u1), [1452]);

section('6. Convites');
function mkInvite(int $tenant, string $email, int $by, int $role, string $status = 'pending', ?int $acceptedUser = null, bool $acceptedAt = false): void
{
    q('INSERT INTO user_invitations (tenant_id,email,invited_by,role_id,token_hash,status,expires_at,accepted_at,accepted_user_id) VALUES (?,?,?,?,?,?,?,?,?)',
        [$tenant, $email, $by, $role, bin2hex(random_bytes(32)), $status, '2030-01-01 00:00:00', $acceptedAt ? '2026-01-01 00:00:00' : null, $acceptedUser]);
}
expectOk('convite válido', fn () => mkInvite($A, 'novo@x.test', $u1, $roleA));
expectDbError('convite feito por quem NÃO é membro do tenant é RECUSADO', fn () => mkInvite($A, 'n2@x.test', $u3, $roleA), [1452]);
expectDbError('convite com papel de OUTRO tenant é RECUSADO', fn () => mkInvite($A, 'n3@x.test', $u1, $roleB), [1452]);
expectDbError('segundo convite PENDENTE para o mesmo e-mail/tenant é RECUSADO', fn () => mkInvite($A, 'novo@x.test', $u1, $roleA), [1062]);
expectDbError('mesmo e-mail com outra CAIXA também é RECUSADO', fn () => mkInvite($A, 'NOVO@X.TEST', $u1, $roleA), [1062]);
expectOk('convite revogado não bloqueia novo convite do mesmo e-mail', function () use ($A, $u1, $roleA) { mkInvite($A, 'rev@x.test', $u1, $roleA, 'revoked'); mkInvite($A, 'rev@x.test', $u1, $roleA); return true; });
expectOk('mesmo e-mail pendente em OUTRO tenant é permitido', fn () => mkInvite($B, 'novo@x.test', $u2, $roleB));
expectDbError('convite "accepted" sem accepted_at/accepted_user_id é RECUSADO (CHECK)', fn () => mkInvite($A, 'acc@x.test', $u1, $roleA, 'accepted'), [3819]);
expectOk('convite "accepted" completo', fn () => mkInvite($A, 'acc2@x.test', $u1, $roleA, 'accepted', $u2, true));
expectDbError('aceito por usuário SEM vínculo no tenant é RECUSADO', fn () => mkInvite($A, 'acc3@x.test', $u1, $roleA, 'accepted', $u3, true), [1452]);
expectDbError('token_hash duplicado é RECUSADO', function () use ($pdo, $A, $u1, $roleA) {
    $h = bin2hex(random_bytes(32));
    foreach (['d1@x.test', 'd2@x.test'] as $e) q('INSERT INTO user_invitations (tenant_id,email,invited_by,role_id,token_hash,expires_at) VALUES (?,?,?,?,?,?)', [$A, $e, $u1, $roleA, $h, '2030-01-01 00:00:00']);
}, [1062]);
$col = $pdo->query("SELECT collation_name FROM information_schema.columns WHERE table_schema='" . DB . "' AND table_name='user_invitations' AND column_name='token_hash'")->fetchColumn();
report('token_hash usa ascii_bin (comparação exata)', $col === 'ascii_bin', (string) $col);

section('7. Auditoria');
expectOk('evento fora de tenant (tenant_id NULL)', fn () => q("INSERT INTO audit_logs (uuid,event) VALUES (?, 'auth.login_failed')", [uuid()]));
expectOk('evento com tenant, ator e JSON', fn () => q("INSERT INTO audit_logs (uuid,tenant_id,user_id,event,metadata) VALUES (?,?,?,?,?)", [uuid(), $A, $u1, 'tenant.selected', json_encode(['from_tenant_id' => $B, 'to_tenant_id' => $A])]));
expectDbError('evento com tenant inexistente é RECUSADO', fn () => q("INSERT INTO audit_logs (uuid,tenant_id,event) VALUES (?,?,?)", [uuid(), 999999, 'x']), [1452]);
$micro = $pdo->query("SELECT created_at FROM audit_logs ORDER BY id DESC LIMIT 1")->fetchColumn();
report('created_at guarda microssegundos', (bool) preg_match('/\.\d{6}$/', (string) $micro), (string) $micro);
$hasUpdated = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='" . DB . "' AND table_name='audit_logs' AND column_name='updated_at'")->fetchColumn();
report('audit_logs não possui updated_at', $hasUpdated === 0);

section('8. Compatibilidade do MySQL (riscos R5, R6 e plugin de autenticação)');
// R6: triggers sem SUPER com log_bin=1 e log_bin_trust_function_creators=0
$pdo->exec("DROP USER IF EXISTS 'app_dev'@'localhost'"); $pdo->exec("DROP USER IF EXISTS 'app_dev'@'127.0.0.1'");
foreach (["'app_dev'@'localhost'", "'app_dev'@'127.0.0.1'"] as $acct) {
    $pdo->exec("CREATE USER $acct IDENTIFIED WITH caching_sha2_password BY 'devpass'");
    $pdo->exec("GRANT ALL PRIVILEGES ON " . DB . ".* TO $acct");
}
try {
    $dev = connect('app_dev', 'devpass');
    report('usuário com caching_sha2_password conecta pelo pdo_mysql do PHP ' . PHP_VERSION, true);
    expectDbError('usuário SEM SUPER não consegue criar TRIGGER (log_bin=1) — confirma "sem triggers"', fn () => $dev->exec("CREATE TRIGGER trg_t BEFORE UPDATE ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='x'"), [1419]);
} catch (Throwable $e) { report('usuário caching_sha2_password conecta', false, $e->getMessage()); }
expectOk('root (SUPER) consegue criar trigger — por isso o dev local "funcionaria" e produção não', function () use ($pdo) {
    $pdo->exec("CREATE TRIGGER trg_probe BEFORE UPDATE ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='x'");
    $pdo->exec('DROP TRIGGER trg_probe'); return true;
});
// R6: privilégios de produção para audit_logs (INSERT/SELECT apenas)
foreach (["'app_prod'@'localhost'", "'app_prod'@'127.0.0.1'"] as $acct) {
    $pdo->exec("DROP USER IF EXISTS $acct"); $pdo->exec("CREATE USER $acct IDENTIFIED WITH caching_sha2_password BY 'prodpass'");
    foreach ($tables as $t) {
        $priv = $t === 'audit_logs' ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE, DELETE';
        $pdo->exec("GRANT $priv ON " . DB . ".`$t` TO $acct");
    }
}
$prod = connect('app_prod', 'prodpass');
expectOk('usuário de produção INSERE em audit_logs', fn () => $prod->exec("INSERT INTO audit_logs (uuid,event) VALUES ('" . uuid() . "','auth.login')") === 1);
expectDbError('usuário de produção NÃO consegue UPDATE em audit_logs', fn () => $prod->exec("UPDATE audit_logs SET event='x'"), [1142]);
expectDbError('usuário de produção NÃO consegue DELETE em audit_logs', fn () => $prod->exec("DELETE FROM audit_logs"), [1142]);
// R5: fuso horário
$tz1 = connect(); $tz1->exec("SET time_zone='-04:00'");
$tz1->exec("INSERT INTO tenant_settings (tenant_id,setting_key,setting_value,created_at) VALUES ($B,'tz_probe','1','2026-01-01 12:00:00')");
$tzUtc = connect(); $tzUtc->exec("SET time_zone='+00:00'");
$seen = $tzUtc->query("SELECT created_at FROM tenant_settings WHERE setting_key='tz_probe'")->fetchColumn();
report('DEMONSTRAÇÃO R5: sessão fora de UTC DESLOCA o TIMESTAMP (gravou 12:00, lê-se ' . $seen . ' em UTC)', $seen !== '2026-01-01 12:00:00', (string) $seen);
$tzUtc->exec("INSERT INTO tenant_settings (tenant_id,setting_key,setting_value,created_at) VALUES ($B,'tz_ok','1','2026-01-01 12:00:00')");
$seen2 = $tzUtc->query("SELECT created_at FROM tenant_settings WHERE setting_key='tz_ok'")->fetchColumn();
report('com time_zone=+00:00 na conexão o round-trip é exato', $seen2 === '2026-01-01 12:00:00', (string) $seen2);

// ---------------------------------------------------------------- PARTE 2
section('9. Spatie Permission (Teams) + Sanctum REAIS sobre o esquema');
$api = 'C:/laragon/www/obraflow/obraflow-api';
foreach (['DB_CONNECTION' => 'mysql', 'DB_HOST' => HOST, 'DB_PORT' => (string) PORT, 'DB_DATABASE' => DB, 'DB_USERNAME' => 'root', 'DB_PASSWORD' => '',
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'APP_ENV' => 'testing', 'APP_DEBUG' => 'true'] as $k => $v) {
    putenv("$k=$v"); $_ENV[$k] = $v; $_SERVER[$k] = $v;
}
require $api . '/vendor/autoload.php';
$app = require $api . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Database\Eloquent\Relations\Relation;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class SbUser extends App\Models\User
{
    use Laravel\Sanctum\HasApiTokens;
    use Spatie\Permission\Traits\HasRoles;
    protected $table = 'users';
    protected $guard_name = 'web'; // mitigação R9: guard único para sessão e token
}
$g1 = json_encode(Spatie\Permission\Guard::getNames(App\Models\User::class)->all());
$g2 = json_encode(Spatie\Permission\Guard::getNames(SbUser::class)->all());
report("R9 achado: Guard::getNames(App\\Models\\User)=$g1 | SbUser (com \$guard_name fixo)=$g2", true);
Relation::enforceMorphMap(['user' => SbUser::class]);
$reg = app(PermissionRegistrar::class);
$team = fn (?int $t) => tap($reg, fn ($r) => $r->setPermissionsTeamId($t));
$fresh = fn (int $id) => SbUser::query()->findOrFail($id);

$s1 = mkUser('sp1@x.test'); $s2 = mkUser('sp2@x.test'); $s3 = mkUser('sp3@x.test');
mkMember($A, $s1); mkMember($A, $s2); mkMember($B, $s2);
q("INSERT INTO permissions (name,guard_name,module) VALUES ('members.invite_sp','web','members')");
Permission::query()->where('name', 'members.invite_sp')->firstOrFail();

$team(null);
expectDbError('Spatie: Role::create SEM contexto de tenant FALHA FECHADO (tenant_id NULL recusado)', fn () => Role::create(['name' => 'sem_tenant', 'guard_name' => 'web']), [1048]);

$team($A);
$spaAdmin = Role::create(['name' => 'spa_admin', 'guard_name' => 'web']);
report('Spatie: papel criado sob o contexto A recebe tenant_id=A', (int) $spaAdmin->tenant_id === $A, 'tenant_id=' . $spaAdmin->tenant_id);
$spaAdmin->givePermissionTo('members.invite_sp');
$team($B);
$spbViewer = Role::create(['name' => 'spb_viewer', 'guard_name' => 'web']);
report('Spatie: papel criado sob o contexto B recebe tenant_id=B', (int) $spbViewer->tenant_id === $B);

$team($A); $fresh($s1)->assignRole('spa_admin');
report('Spatie: s1 é admin em A', $fresh($s1)->hasRole('spa_admin'));
$team($B);
report('Spatie: s1 NÃO tem papel admin ao trocar para B (sem vínculo em B)', !$fresh($s1)->hasRole('spa_admin'));

$team($A); $fresh($s2)->assignRole('spa_admin');
$team($B); $fresh($s2)->assignRole('spb_viewer');
$team($A); $u = $fresh($s2);
report('Spatie: s2 em A — é admin e pode members.invite_sp', $u->hasRole('spa_admin') && $u->can('members.invite_sp'));
$team($B); $u = $fresh($s2);
report('Spatie: s2 em B — é viewer, NÃO é admin e NÃO pode members.invite_sp', $u->hasRole('spb_viewer') && !$u->hasRole('spa_admin') && !$u->can('members.invite_sp'));

// instância reutilizada ao trocar de tenant (armadilha documentada)
$team($A); $same = $fresh($s2); $same->hasRole('spa_admin'); $same->can('members.invite_sp');
$team($B);
$stale = $same->hasRole('spa_admin');
$same->unsetRelation('roles')->unsetRelation('permissions');
$clean = $same->hasRole('spa_admin');
report('ARMADILHA: reutilizar o MESMO objeto após trocar de tenant ' . ($stale ? 'MANTÉM o papel do tenant anterior (obrigatório limpar relações)' : 'não vaza (relação refeita)'), true);
report('Spatie: após unsetRelation o papel de A deixa de valer em B', $clean === false);

// tentativa de atribuir papel de OUTRO tenant
$team($A);
$fromB = Role::query()->withoutGlobalScopes()->where('id', $spbViewer->id)->first();
expectDbError('Spatie: assignRole com OBJETO de papel do tenant B sob o contexto A é recusado pelo banco', fn () => $fresh($s1)->assignRole($fromB), [1452]);
$threw = false; try { $fresh($s1)->assignRole('spb_viewer'); } catch (Throwable $e) { $threw = true; }
report('Spatie: assignRole por NOME de papel do tenant B sob o contexto A não encontra o papel', $threw);
expectDbError('Spatie: assignRole a usuário SEM vínculo no tenant é recusado pelo banco', fn () => $fresh($s3)->assignRole('spa_admin'), [1452]);
$globals = (int) $pdo->query('SELECT COUNT(*) FROM roles WHERE tenant_id IS NULL')->fetchColumn();
report('não existe nenhum papel global (tenant_id NULL)', $globals === 0, (string) $globals);

// Sanctum com morph map
$team($A);
$tokenOk = true; $tok = null;
try { $tok = $fresh($s1)->createToken('integracao'); } catch (Throwable $e) { $tokenOk = false; report('Sanctum: createToken com morph map "user"', false, $e->getMessage()); }
if ($tokenOk) {
    report('Sanctum: createToken grava tokenable_type="user" (passa no CHECK)', true);
    $row = $pdo->query('SELECT tokenable_type, tenant_id FROM personal_access_tokens WHERE id=' . $tok->accessToken->id)->fetch(PDO::FETCH_ASSOC);
    report('Sanctum: o token nasce SEM tenant (o pacote não conhece tenant) => middleware deve barrá-lo nos dados de negócio', $row['tenant_id'] === null, json_encode($row));
    expectOk('vincular o token ao tenant A (usuário é membro)', fn () => $pdo->exec('UPDATE personal_access_tokens SET tenant_id=' . $A . ' WHERE id=' . $tok->accessToken->id) === 1);
    expectDbError('vincular o MESMO token ao tenant B (usuário não é membro) é RECUSADO', fn () => $pdo->exec('UPDATE personal_access_tokens SET tenant_id=' . $B . ' WHERE id=' . $tok->accessToken->id), [1452]);
}
$guards = Spatie\Permission\Guard::getNames(SbUser::class)->all();
report('guard_name do usuário para o Spatie = ' . json_encode($guards) . ' (deve ser fixado em "web")', in_array('web', $guards, true), json_encode($guards));

// ---------------------------------------------------------------- RESUMO
echo "\n==============================\n";
echo "RESULTADO: $pass aprovados, $fail reprovados\n";
if ($failures) { echo "Reprovados:\n - " . implode("\n - ", $failures) . "\n"; }
exit($fail === 0 ? 0 : 1);
