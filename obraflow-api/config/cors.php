<?php

/*
| CORS para o SPA (origem diferente da API). Cookies de sessão exigem supports_credentials = true,
| e então a origem NÃO pode ser "*": usa-se a URL do frontend.
*/
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_filter(array_map('trim', explode(',', (string) env('FRONTEND_URL', 'http://localhost:5173')))),

    'allowed_origins_patterns' => [],

    // X-Tenant-Hint: dica NÃO autoritativa do tenant exibido (409 se divergir da sessão)
    'allowed_headers' => ['Accept', 'Content-Type', 'Authorization', 'X-Requested-With', 'X-XSRF-TOKEN', 'X-Request-Id', 'X-Tenant-Hint'],

    'exposed_headers' => ['X-Request-Id'],

    'max_age' => 600,

    'supports_credentials' => true,
];
