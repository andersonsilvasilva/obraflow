<?php

return [
    /*
    | Validade máxima (em dias) de qualquer token de API. A expiração é obrigatória na emissão.
    */
    'api_token_max_days' => (int) env('API_TOKEN_MAX_DAYS', 90),

    /*
    | Validade padrão (em dias) de um convite por e-mail.
    */
    'invitation_ttl_days' => (int) env('INVITATION_TTL_DAYS', 7),

    /*
    | URL do frontend (links de e-mail: recuperação de senha e aceite de convite).
    */
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
];
