<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Google OAuth (Gmail send-only)
    |--------------------------------------------------------------------------
    | The app requests ONLY openid + email + gmail.send. It can send as the
    | connected mailbox and learn its address; it can never read mail.
    */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
        'scopes' => [
            'openid',
            'email',
            'https://www.googleapis.com/auth/gmail.send',
        ],
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        // USD per million tokens: [input, output, cache_read, cache_write].
        // Used only for the on-screen cost estimate; billing is Anthropic's.
        'pricing' => [
            'claude-opus-5' => [5.00, 25.00, 0.50, 6.25],
            'claude-sonnet-5' => [2.00, 10.00, 0.20, 2.50],
            'claude-haiku-4-5' => [1.00, 5.00, 0.10, 1.25],
            'claude-opus-4-8' => [5.00, 25.00, 0.50, 6.25],
        ],
        'models' => [
            'claude-opus-5' => 'Claude Opus 5 (best quality)',
            'claude-sonnet-5' => 'Claude Sonnet 5 (≈2.5× cheaper)',
            'claude-haiku-4-5' => 'Claude Haiku 4.5 (cheapest)',
        ],
    ],

    'smtp' => [
        // Explicit CA bundle for TLS verification (Windows/Laragon has none in php.ini).
        'cafile' => env('SMTP_CAFILE'),
        'timeout_seconds' => 20,
    ],

    'attachments' => [
        'max_total_mb' => 10,
        'allowed_extensions' => ['pdf', 'png', 'jpg', 'jpeg', 'docx', 'xlsx', 'pptx'],
        'allowed_mimes' => [
            'application/pdf',
            'image/png',
            'image/jpeg',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ],
    ],

    // A `pending` send older than this is swept to `unknown` (the request died
    // mid-flight; the message may or may not have left).
    'pending_stale_minutes' => 10,

    // A lead left in `generating` longer than this is released back to pending.
    'generating_stale_minutes' => 5,

    // Leads processed per generate request (browser loops until none remain).
    'generate_batch' => 2,

    // CSV import limits.
    'import' => [
        'max_rows' => 5000,
        'max_attribute_chars' => 500,
        'max_attributes_chars' => 4000,
    ],
];
