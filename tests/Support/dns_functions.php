<?php

/**
 * Declares the two resolver names inside the namespace of `App\Rules\SafeUrl`.
 *
 * Require this file from a test that must count host resolutions. Both names
 * forward to the real PHP function until a test calls `Tests\Support\DnsSpy`.
 */

namespace App\Rules;

use Tests\Support\DnsSpy;

if (! function_exists('App\Rules\gethostbynamel')) {
    /**
     * @return array<int, string>|false
     */
    function gethostbynamel(string $hostname)
    {
        return DnsSpy::gethostbynamel($hostname);
    }
}

if (! function_exists('App\Rules\dns_get_record')) {
    /**
     * @return array<int, array<string, mixed>>|false
     */
    function dns_get_record(string $hostname, int $type = DNS_ANY)
    {
        return DnsSpy::dnsGetRecord($hostname, $type);
    }
}
