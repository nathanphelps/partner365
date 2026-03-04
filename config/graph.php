<?php

return [
    'tenant_id' => env('MICROSOFT_GRAPH_TENANT_ID'),
    'client_id' => env('MICROSOFT_GRAPH_CLIENT_ID'),
    'client_secret' => env('MICROSOFT_GRAPH_CLIENT_SECRET'),
    'scopes' => env('MICROSOFT_GRAPH_SCOPES', 'https://graph.microsoft.com/.default'),
    'base_url' => env('MICROSOFT_GRAPH_BASE_URL', 'https://graph.microsoft.com/v1.0'),
    'sync_interval_minutes' => env('MICROSOFT_GRAPH_SYNC_INTERVAL', 15),
];
