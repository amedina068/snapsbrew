<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Set TRUSTED_PROXIES when a reverse proxy terminates TLS in front of the
    | app, so that the app builds https URLs and reads the client IP. Give the
    | addresses or CIDR ranges of each proxy, comma separated. Do not trust
    | every address: then any client can choose its own IP and pass the per
    | IP limits.
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),

];
