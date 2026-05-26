<?php

declare(strict_types=1);

return [
    'fallback_monitor_domain' => 'https://whitesmoke-camel-166125.hostingersite.com',

    'endpoints' => [
        'updates' => '/api/webhooks/wp-update',
        'plugins' => '/api/webhooks/wp-plugins',
        'users' => '/api/webhooks/wp-users',
        'ping' => '/api/webhooks/wp-update/ping',
    ],
];
