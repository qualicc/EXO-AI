<?php

return [
    'catalog_path' => env('EXOPLANET_CATALOG_CSV', storage_path('app/exoplanets/catalog.csv')),

    'queue' => env('EXOPLANET_QUEUE', 'exoplanet-predictions'),

    'status_poll_seconds' => (int) env('EXOPLANET_STATUS_POLL_SECONDS', 5),

    'submit_lock_seconds' => (int) env('EXOPLANET_SUBMIT_LOCK_SECONDS', 600),

    'ai' => [
        'timeout' => (int) env('EXOPLANET_AI_TIMEOUT', 20),
    ],
];
