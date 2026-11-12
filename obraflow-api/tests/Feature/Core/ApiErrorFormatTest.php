<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Route::prefix('api/v1/_test')->group(function () {
        Route::get('boom', fn () => throw new RuntimeException('segredo interno'));
        Route::get('invalid', fn () => throw ValidationException::withMessages(['email' => ['Obrigatorio.']]));
        Route::get('forbidden', fn () => abort(403));
    });
});

it('retorna 404 padronizado para rota inexistente', function () {
    $this->getJson('/api/v1/nao-existe')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found')
        ->assertJsonStructure(['error' => ['code', 'message', 'request_id']]);
});

it('retorna 405 padronizado para metodo nao permitido', function () {
    $this->postJson('/api/v1/health')
        ->assertStatus(405)
        ->assertJsonPath('error.code', 'method_not_allowed');
});

it('retorna 422 com detalhes de validacao', function () {
    $this->getJson('/api/v1/_test/invalid')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.details.email.0', 'Obrigatorio.');
});

it('retorna 403 padronizado', function () {
    $this->getJson('/api/v1/_test/forbidden')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');
});

it('nao vaza detalhes internos em erros 500 fora de debug', function () {
    config(['app.debug' => false]);

    $response = $this->getJson('/api/v1/_test/boom')->assertStatus(500);

    $response->assertJsonPath('error.code', 'server_error');
    expect($response->getContent())->not->toContain('segredo interno');
});

it('aceita X-Request-Id valido e rejeita valor malicioso', function () {
    $this->getJson('/api/v1/health', ['X-Request-Id' => 'abc12345-req'])
        ->assertHeader('X-Request-Id', 'abc12345-req');

    $response = $this->getJson('/api/v1/health', ['X-Request-Id' => '<script>x</script>']);
    expect($response->headers->get('X-Request-Id'))->not->toContain('<script>');
});
