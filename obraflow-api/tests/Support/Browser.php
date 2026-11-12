<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * "Navegador" de teste: mantém o cookie jar entre requisições e envia Origin/Referer do SPA,
 * de modo que o Sanctum trate a requisição como stateful (sessão por cookie).
 * Vários Browser no mesmo teste = várias abas/dispositivos independentes.
 */
final class Browser
{
    /** @var array<string, string> */
    private array $jar = [];

    public function __construct(private readonly TestCase $test, private readonly string $origin = 'http://localhost:5173') {}

    /** @param  array<string, mixed>  $data @param  array<string, string>  $headers */
    public function request(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        $headers += ['Origin' => $this->origin, 'Referer' => $this->origin.'/'];

        // reinicia os cookies do caso de teste com EXATAMENTE o jar deste navegador
        (function (array $jar): void {
            $this->defaultCookies = [];
            $this->unencryptedCookies = [];
            $this->withUnencryptedCookies($jar);
            // requisições JSON só enviam cookies com withCredentials() — sem isto o "navegador" ficaria deslogado
            $this->withCredentials();
        })->call($this->test, $this->jar);

        $response = $this->test->json($method, $uri, $data, $headers);

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getExpiresTime() !== 0 && $cookie->getExpiresTime() < time()) {
                unset($this->jar[$cookie->getName()]);
            } else {
                $this->jar[$cookie->getName()] = (string) $cookie->getValue();
            }
        }

        return $response;
    }

    public function get(string $uri, array $headers = []): TestResponse
    {
        return $this->request('GET', $uri, [], $headers);
    }

    public function post(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->request('POST', $uri, $data, $headers);
    }

    public function put(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->request('PUT', $uri, $data, $headers);
    }

    public function delete(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->request('DELETE', $uri, $data, $headers);
    }

    public function login(string $email, string $password = Scenario::PASSWORD): TestResponse
    {
        return $this->post('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    }

    public function sessionCookie(): ?string
    {
        foreach ($this->jar as $name => $value) {
            if (str_ends_with($name, '-session')) {
                return $value;
            }
        }

        return null;
    }
}
