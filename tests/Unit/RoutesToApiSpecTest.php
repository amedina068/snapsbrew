<?php

namespace Tests\Unit;

use App\Support\ApiDocs\ApiSpec;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * `ApiSpec` is the one source of truth for the public API documents. The
 * OpenAPI schema, the Postman collection, the Markdown and the documentation
 * screens all generate from it.
 *
 * Nothing walked the routes against that source before this test.
 * `tests/Unit/ApiSpecTest.php` reads the content of groups that are already
 * documented, so a route with no entry failed nothing. Seven routes reached
 * production undocumented that way, and ten new routes can ship with nine
 * documented and the build stays green.
 *
 * This test walks both directions. A routed endpoint with no entry fails, and
 * an entry with no route fails.
 *
 * The routes come from the router, not from a read of `routes/api.php`. The
 * router is what the customer calls. A file read passes when the file declares
 * a route that a service provider never registers.
 */
class RoutesToApiSpecTest extends TestCase
{
    /**
     * Routes of the free public tools. Each one renders one fixed template for
     * a form on the marketing site. None takes a token and none belongs in the
     * customer-facing document set.
     *
     * This is a list of four real routes, never a `v1/tools/*` pattern. A
     * pattern absorbs the next route that matches it, which is the failure this
     * test exists to prevent.
     *
     * @var array<string, string>
     */
    private const TOOL_ROUTES = [
        'POST /v1/tools/certificate-creator' => 'Public free tool. It backs the certificate form of the marketing site and takes no token.',
        'POST /v1/tools/twitter-graph-image-creator' => 'Public free tool. It backs the Twitter graph form of the marketing site and takes no token.',
        'POST /v1/tools/testimonial-image-creator' => 'Public free tool. It backs the testimonial form of the marketing site and takes no token.',
        'POST /v1/tools/screenshot-creator' => 'Public free tool. It backs the screenshot form of the marketing site and takes no token.',
    ];

    /**
     * Routes declared outside `routes/api.php`. The rule in `CLAUDE.md` binds
     * `routes/api.php` to `ApiSpec`, so a route from another file is out of
     * that rule and is listed here by name. The embedded form is a signed-URL
     * surface for a browser, not a bearer token surface for a client.
     *
     * @var array<string, string>
     */
    private const ROUTES_FROM_OTHER_FILES = [
        'POST /v1/embedded-forms/{formUuid}' => 'Declared in routes/api-embedded-form.php, not routes/api.php.',
        'GET /creative-packages/{package}/items/{item}/file' => 'Declared in routes/api-creative-package-media.php. A signed bearer URL the package response issues, not a documented endpoint.',
        'GET /creative-packages/{package}/items/{item}/preview' => 'Declared in routes/api-creative-package-media.php. A signed bearer URL the package response issues, not a documented endpoint.',
    ];

    /**
     * `ApiSpec` ids that belong to an excluded route.
     *
     * An exclusion has a cost that is easy to miss. It takes a route out of the
     * first direction, and the second direction is entry-driven, so it judges
     * the entries that are there and cannot see one that is gone. Measured:
     * with the embedded form excluded, deleting `groupEmbeddedForms()` from
     * `ApiSpec::groups()` left all 960 tests green while the OpenAPI schema,
     * the Postman collection and the documentation screen all lost the
     * endpoint. An exclusion still owes its entry a guard, so name the id here.
     *
     * @var array<int, string>
     */
    private const EXCLUDED_ROUTES_WITH_A_SPEC_ENTRY = ['embedded-forms.store'];

    /** A route the reader must return. It proves the reader read something. */
    private const SENTINEL_ROUTE = 'POST /v1/snaps';

    /**
     * An entry on the site root (`base: site`) documents a route outside
     * `/api`. The two tests above read `/api` routes only, so this one reads
     * the whole router for those entries.
     */
    public function test_every_site_root_entry_matches_a_routed_endpoint()
    {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                $routes[] = $method.' /'.$route->uri();
            }
        }

        $entries = array_filter(ApiSpec::endpoints(), fn (array $endpoint) => ($endpoint['base'] ?? 'api') === 'site');

        $this->assertNotEmpty($entries, 'ApiSpec documents no site root route, so this test reads nothing.');

        foreach ($entries as $endpoint) {
            $this->assertContains(strtoupper($endpoint['method']).' '.$endpoint['path'], $routes, "ApiSpec entry [{$endpoint['id']}] names no route.");
        }
    }

    public function test_every_routed_api_endpoint_has_an_api_spec_entry()
    {
        $excluded = array_merge(array_keys(self::TOOL_ROUTES), array_keys(self::ROUTES_FROM_OTHER_FILES));

        $undocumented = array_values(array_diff($this->routedEndpoints(), $this->specEndpoints(), $excluded));

        $this->assertSame([], $undocumented, 'These routes have no ApiSpec entry. Add each one to ApiSpec.php.');
    }

    public function test_every_api_spec_entry_matches_a_routed_endpoint()
    {
        $unrouted = array_values(array_diff($this->specEndpoints(), $this->routedEndpoints()));

        $this->assertSame([], $unrouted, 'These ApiSpec entries document an endpoint the router does not serve.');
    }

    public function test_every_excluded_route_is_a_route_the_router_serves()
    {
        $routes = $this->routedEndpoints();

        foreach (array_merge(self::TOOL_ROUTES, self::ROUTES_FROM_OTHER_FILES) as $endpoint => $reason) {
            $this->assertContains($endpoint, $routes, "Exclusion [{$endpoint}] names no route. Delete the line.");
        }
    }

    /**
     * An exclusion takes a route out of the first test, so no test above can
     * see its `ApiSpec` entry disappear. This one can.
     */
    public function test_an_excluded_route_still_has_its_api_spec_entry()
    {
        $missing = array_diff(self::EXCLUDED_ROUTES_WITH_A_SPEC_ENTRY, array_column(ApiSpec::endpoints(), 'id'));

        $this->assertSame([], array_values($missing), 'An excluded route lost its ApiSpec entry.');
    }

    /**
     * Every API endpoint the router serves, as "METHOD /path".
     *
     * The filter reads `api/`, not `api/v1/`. A reader pinned to one version
     * cannot see the first route of the next version, so the day someone adds
     * `api/v2/...` the test that proves every route has an entry would pass
     * without reading the new route at all.
     *
     * `HEAD` and `OPTIONS` are dropped. Laravel adds both to a route the
     * application never declares, and no document describes them.
     *
     * @return array<int, string>
     */
    private function routedEndpoints(): array
    {
        $out = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (strpos($uri, 'api/') !== 0) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD' || $method === 'OPTIONS') {
                    continue;
                }

                $out[] = $method.' '.substr($uri, strlen('api'));
            }
        }

        sort($out);

        // Every caller asserts an absence, and an absence over an empty list
        // passes in silence. Prove the reader worked before a caller reads it.
        $this->assertContains(self::SENTINEL_ROUTE, $out, 'The route reader returned no known route.');

        return $out;
    }

    /**
     * Every endpoint `ApiSpec` documents, in the same "METHOD /path" shape.
     *
     * @return array<int, string>
     */
    private function specEndpoints(): array
    {
        $out = [];

        foreach (ApiSpec::endpoints() as $endpoint) {
            // A site root entry is outside `/api`. The test above reads it.
            if (($endpoint['base'] ?? 'api') === 'site') {
                continue;
            }

            $out[] = strtoupper($endpoint['method']).' '.$endpoint['path'];
        }

        sort($out);

        $this->assertNotEmpty($out, 'ApiSpec returned no endpoint, so the caller asserts nothing.');

        return $out;
    }
}
