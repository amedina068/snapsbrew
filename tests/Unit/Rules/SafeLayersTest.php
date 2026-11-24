<?php

namespace Tests\Unit\Rules;

use App\Rules\SafeLayers;
use PHPUnit\Framework\TestCase;
use Tests\Support\DnsSpy;

class SafeLayersTest extends TestCase
{
    private function layersWithBackground($value): array
    {
        return [[
            'key' => 'bg',
            'type' => 'image',
            'properties' => [
                ['name' => 'backgroundImage', 'value' => $value],
            ],
        ]];
    }

    private function passes($value): bool
    {
        return (new SafeLayers)->passes('layers', $this->layersWithBackground($value));
    }

    public function test_allows_empty_value()
    {
        $this->assertTrue($this->passes(''));
    }

    public function test_allows_relative_path()
    {
        $this->assertTrue($this->passes('/images/bg.png'));
    }

    public function test_allows_data_uri()
    {
        $this->assertTrue($this->passes('data:image/png;base64,iVBORw0KGgo='));
    }

    public function test_rejects_file_scheme()
    {
        $this->assertFalse($this->passes('file:///etc/passwd'));
    }

    public function test_rejects_internal_ip()
    {
        $this->assertFalse($this->passes('http://10.0.0.1/x.png'));
        $this->assertFalse($this->passes('http://169.254.169.254/latest/meta-data/'));
    }

    /**
     * `parse_url()` reads `http:169.254.169.254/x` as a path with no host.
     * Chrome reads the same value as a URL on the host 169.254.169.254.
     */
    public function test_rejects_an_http_scheme_without_two_slashes()
    {
        foreach ([
            'http:169.254.169.254/latest/meta-data/',
            'http:/10.0.0.5/x.png',
            'HTTPS:example.com/x.png',
            'http:\\\\10.0.0.5\\x.png',
        ] as $value) {
            $this->assertFalse($this->passes($value), $value);
        }
    }

    public function test_rejects_backslashes_that_chrome_reads_as_a_scheme_relative_url()
    {
        $this->assertFalse($this->passes('\\\\127.0.0.1\\x.png'));
        $this->assertFalse($this->passes('/\\127.0.0.1/x.png'));
    }

    public function test_rejects_whitespace_that_chrome_drops_from_a_url()
    {
        $this->assertFalse($this->passes(' //127.0.0.1/x.png'));
        $this->assertFalse($this->passes("/\t/127.0.0.1/x.png"));
        $this->assertFalse($this->passes("http\n://10.0.0.5/x.png"));
    }

    public function test_rejects_scheme_relative_internal_host()
    {
        $this->assertFalse($this->passes('//127.0.0.1/x.png'));
    }

    public function test_ignores_non_background_image_properties()
    {
        $layers = [[
            'key' => 't', 'type' => 'text',
            'properties' => [['name' => 'textContent', 'value' => 'file:///etc/passwd']],
        ]];

        $this->assertTrue((new SafeLayers)->passes('layers', $layers));
    }

    /**
     * @param  array<int, string>  $urls
     * @return array<int, array<string, mixed>>
     */
    private function layersWithBackgrounds(array $urls): array
    {
        $layers = [];

        foreach ($urls as $index => $url) {
            $layers[] = [
                'key' => 'layer_'.$index,
                'type' => 'image',
                'properties' => [['name' => 'backgroundImage', 'value' => $url]],
            ];
        }

        return $layers;
    }

    /**
     * One `SafeUrl` pass costs two resolver calls for one host.
     *
     * `SafeUrl::resolveIps()` calls `gethostbynamel()` for the A records and
     * `dns_get_record()` for the AAAA records, so the spy records two entries
     * per pass.
     */
    private const CALLS_PER_RESOLUTION = 2;

    /**
     * A repeated URL resolves its host one time.
     *
     * Before the memo, 50 layers on one host measured 100 resolver calls. PHP
     * exposes no resolver timeout, so every repeat is unbounded wall time
     * inside one validation pass.
     */
    public function test_a_repeated_url_resolves_its_host_one_time()
    {
        require_once __DIR__.'/../../Support/dns_functions.php';

        $spy = DnsSpy::start();

        try {
            $urls = array_fill(0, 50, 'https://images.example.com/logo.png');

            $this->assertTrue(
                (new SafeLayers)->passes('layers', $this->layersWithBackgrounds($urls))
            );

            $this->assertSame(
                self::CALLS_PER_RESOLUTION,
                $spy->callsFor('images.example.com')
            );
        } finally {
            DnsSpy::stop();
        }
    }

    /**
     * The memo is keyed by the URL, so a second host is still resolved.
     *
     * A memo that skipped every URL after the first would let an internal host
     * through behind one public host.
     */
    public function test_a_second_host_is_resolved()
    {
        require_once __DIR__.'/../../Support/dns_functions.php';

        $spy = DnsSpy::start();

        try {
            $this->assertTrue((new SafeLayers)->passes('layers', $this->layersWithBackgrounds([
                'https://images.example.com/logo.png',
                'https://images.example.com/logo.png',
                'https://cdn.example.net/logo.png',
            ])));

            $this->assertSame(self::CALLS_PER_RESOLUTION, $spy->callsFor('images.example.com'));
            $this->assertSame(self::CALLS_PER_RESOLUTION, $spy->callsFor('cdn.example.net'));
        } finally {
            DnsSpy::stop();
        }
    }

    /**
     * The memo keys on the URL string, not on the host.
     *
     * Two spellings of one host cost two resolutions. An explicit default
     * port, a cache-busting query parameter and a different letter case all
     * produce a second entry. This test records the accepted behavior, so a
     * later reader does not treat host-level reuse as a promise the code
     * makes, and does not read the second resolution as a regression.
     *
     * A memo keyed on the host is not the obvious improvement it looks like:
     * `SafeUrl` reads the whole URL, and two URLs of one host can differ in
     * the parts it reads.
     */
    public function test_two_spellings_of_one_host_each_resolve()
    {
        require_once __DIR__.'/../../Support/dns_functions.php';

        $spy = DnsSpy::start();

        try {
            $this->assertTrue((new SafeLayers)->passes('layers', $this->layersWithBackgrounds([
                'https://images.example.com/logo.png',
                'https://images.example.com:443/logo.png',
            ])));

            $this->assertSame(
                self::CALLS_PER_RESOLUTION * 2,
                $spy->callsFor('images.example.com')
            );
        } finally {
            DnsSpy::stop();
        }
    }
}
