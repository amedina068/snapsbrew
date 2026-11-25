<?php

use App\Support\RuntimeDefaults;

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
    | With APP_DOMAIN and no TRUSTED_PROXIES, the app trusts 127.0.0.1: the
    | Caddy in the Docker image sends each HTTPS request from that address.
    |
    */

    'proxies' => RuntimeDefaults::trustedProxies(env('TRUSTED_PROXIES'), env('APP_DOMAIN')),

];
