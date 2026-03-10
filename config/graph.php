<?php

return [
    'cloud_environment' => env('MICROSOFT_GRAPH_CLOUD_ENVIRONMENT', 'commercial'),
    'tenant_id' => env('MICROSOFT_GRAPH_TENANT_ID'),
    'client_id' => env('MICROSOFT_GRAPH_CLIENT_ID'),
    'client_secret' => env('MICROSOFT_GRAPH_CLIENT_SECRET'),
    'scopes' => env('MICROSOFT_GRAPH_SCOPES', 'https://graph.microsoft.com/.default'),
    'base_url' => env('MICROSOFT_GRAPH_BASE_URL', 'https://graph.microsoft.com/v1.0'),
    'sharepoint_tenant' => env('MICROSOFT_GRAPH_SHAREPOINT_TENANT'),
    'compliance_certificate_path' => env('COMPLIANCE_CERTIFICATE_PATH'),
    'compliance_certificate_password' => env('COMPLIANCE_CERTIFICATE_PASSWORD'),
    'sync_site_users' => env('MICROSOFT_GRAPH_SYNC_SITE_USERS', true),
    'sync_interval_minutes' => env('MICROSOFT_GRAPH_SYNC_INTERVAL', 15),
];
