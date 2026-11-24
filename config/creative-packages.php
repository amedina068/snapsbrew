<?php

// The attempt backoff list and the diagnostic byte width are fixed contract
// ceilings on CreativePackageContract. No key here changes them.
return [
    'limits' => [
        'max_items' => 10,
        'request_bytes' => 256 * 1024,
        'metadata_bytes' => 16 * 1024,
        'metadata_depth' => 6,
        'active_per_company' => 3,
        'lease_seconds' => 5 * 60,
        'heartbeat_seconds' => 30,
        'attempt_timeout_seconds' => 120,
        'max_attempts' => 3,
        'url_ttl_seconds' => 10 * 60,
        'replay_days' => 90,
        'diagnostic_days' => 30,
        'retention_limit' => 1000,
        'retention_page' => 100,
    ],
];
