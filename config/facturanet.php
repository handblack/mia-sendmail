<?php

return [
    'eligible_states' => ['05'],
    'eligible_document_types' => ['01', '03', '07'],
    'minimum_issue_date' => '2026-11-18',

    'http' => [
        'connect_timeout' => (int) env('FACTURANET_HTTP_CONNECT_TIMEOUT', 10),
        'timeout' => (int) env('FACTURANET_HTTP_TIMEOUT', 45),
        'max_redirects' => (int) env('FACTURANET_HTTP_MAX_REDIRECTS', 2),
        'max_bytes' => (int) env('FACTURANET_HTTP_MAX_BYTES', 15 * 1024 * 1024),
        'allowed_hosts' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('FACTURANET_ALLOWED_HOSTS', 'anticipate.facturanet.pe'))
        ))),
    ],

    'mail' => [
        // Safety lock for this implementation stage. Change only after explicit authorization.
        'allow_real_recipients' => false,
        'safety_recipient' => 'soporte@miasoftware.net',
        'sender_name' => env('DOCUMENT_MAIL_FROM_NAME', 'Comprobante electrónico'),
        'default_subject' => env('DOCUMENT_MAIL_SUBJECT', 'Envío de Comprobante de Pago Electrónico'),
    ],

    'processing' => [
        'lock_minutes' => (int) env('DOCUMENT_PROCESSING_LOCK_MINUTES', 10),
        'scheduler_enabled' => (bool) env('DOCUMENT_SYNC_SCHEDULER_ENABLED', false),
        'queue' => env('DOCUMENT_QUEUE', 'documents'),
    ],
];
