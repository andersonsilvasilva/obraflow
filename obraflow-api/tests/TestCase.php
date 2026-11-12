<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Trava de segurança: os testes usam RefreshDatabase (migrate:fresh) e apagariam o banco.
     * Se algo apontar para outro banco, aborta ANTES de qualquer operação.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $database = config('database.connections.'.config('database.default').'.database');

        if ($database !== 'obraflow_testing') {
            $this->fail("Testes só podem rodar no banco 'obraflow_testing' (encontrado: '{$database}').");
        }
    }

    /**
     * Em produção cada requisição é um processo novo. Dentro de um teste o app é reaproveitado, e os guards
     * (RequestGuard do Sanctum, SessionGuard) guardam em cache o usuário da requisição anterior. Para que
     * várias requisições no mesmo teste se comportem como requisições reais, esquece guards e instâncias
     * com escopo (ex.: TenantContext) antes de cada uma.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetScopedInstances();
        // O Store de sessão é singleton e MESCLA o que carrega com o que já está em memória: sem isto, dados
        // da sessão de uma requisição vazam para a seguinte (inexistente em produção: um processo por requisição).
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        // A Route guarda o controller em cache entre requisições; ele reteria serviços com escopo (injetados no
        // construtor) da requisição anterior. O Octane faz exatamente este flush entre requisições.
        foreach ($this->app['router']->getRoutes() as $route) {
            $route->flushController();
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
