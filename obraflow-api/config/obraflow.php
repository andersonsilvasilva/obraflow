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

    /*
    | Documentos (D-P15): disco privado (local no desenvolvimento; S3 por configuração), sem URL pública.
    | O acesso é por URL assinada de curta duração. Limites conservadores até haver antivírus (FASE2_MODELO §6).
    */
    'documents' => [
        'disk' => env('DOCUMENTS_DISK', 'documents'),
        'max_kb' => (int) env('DOCUMENTS_MAX_KB', 20480),
        'signed_url_minutes' => (int) env('DOCUMENTS_SIGNED_URL_MINUTES', 5),
        'allowed_mimes' => [
            'application/pdf', 'image/png', 'image/jpeg', 'image/webp',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ],
    ],
];
