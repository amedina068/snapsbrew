<?php

namespace Tests\Support;

/**
 * Counts the host resolutions `App\Rules\SafeUrl` makes, with no network.
 *
 * `SafeUrl` sits in the namespace `App\Rules` and calls `gethostbynamel()` and
 * `dns_get_record()` unqualified. PHP resolves an unqualified function call at
 * run time: it reads the current namespace first and the global namespace
 * second. `tests/Support/dns_functions.php` declares the two names inside
 * `App\Rules`, so every call of `SafeUrl` arrives here.
 *
 * The spy is off until a test calls `start()`. While it is off, both names
 * forward to the real PHP function, so every other test keeps the behavior it
 * had.
 */
class DnsSpy
{
    private static ?self $active = null;

    /** @var array<int, string> */
    public array $calls = [];

    /** @var array<int, string> */
    private array $answer;

    /**
     * @param  array<int, string>  $answer  IP addresses every host resolves to.
     */
    private function __construct(array $answer)
    {
        $this->answer = $answer;
    }

    /**
     * Start counting. Every host resolves to the address list given.
     *
     * @param  array<int, string>  $answer
     */
    public static function start(array $answer = ['93.184.216.34']): self
    {
        return self::$active = new self($answer);
    }

    public static function stop(): void
    {
        self::$active = null;
    }

    /**
     * How many times the two resolvers ran for one host.
     */
    public function callsFor(string $host): int
    {
        return count(array_filter($this->calls, fn ($called) => $called === $host));
    }

    /**
     * @return array<int, string>|false
     */
    public static function gethostbynamel(string $hostname)
    {
        if (self::$active === null) {
            return \gethostbynamel($hostname);
        }

        self::$active->calls[] = $hostname;

        return self::$active->answer;
    }

    /**
     * @return array<int, array<string, mixed>>|false
     */
    public static function dnsGetRecord(string $hostname, int $type)
    {
        if (self::$active === null) {
            return @\dns_get_record($hostname, $type);
        }

        self::$active->calls[] = $hostname;

        // `SafeUrl` reads only the `ipv6` key of each record. An empty answer
        // leaves the IPv4 address list above as the whole result.
        return [];
    }
}
