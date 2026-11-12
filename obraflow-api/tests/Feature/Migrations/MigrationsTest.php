<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Comportamento das migrations no MySQL do ambiente (8.0.30 local; 8.0 e 8.4 no CI).
 * Não usa RefreshDatabase: ele próprio executa migrate:fresh/rollback. Termina SEMPRE com o banco migrado.
 */
function tableCount(): int
{
    return (int) DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name <> 'migrations'")->n;
}

it('o MySQL em uso é compatível com o modelo (>= 8.0.16: CHECK aplicado)', function () {
    $version = DB::selectOne('SELECT VERSION() AS v')->v;

    expect(version_compare($version, '8.0.16', '>='))->toBeTrue("MySQL {$version} não aplica CHECK");
});

it('migrate:fresh cria as 15 tabelas, rollback remove todas e migrate recria (up/down simétricos)', function () {
    Artisan::call('migrate:fresh', ['--force' => true]);
    expect(tableCount())->toBe(15);

    Artisan::call('migrate:rollback', ['--force' => true, '--step' => 15]);
    expect(tableCount())->toBe(0);

    Artisan::call('migrate', ['--force' => true]);
    expect(tableCount())->toBe(15);
});
