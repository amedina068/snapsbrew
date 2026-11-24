<?php

namespace Tests\Feature;

use App\Contracts\SnapRenderer;
use App\Models\ApiKey;
use App\Models\Company;
use App\Models\Snap;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FakeRenderer;
use Tests\TestCase;

/**
 * The signed URL guide shows code that works.
 *
 * The guide once told readers to encode `variants` as base64url and to sign
 * the URL-encoded query. `SignedUrlController` decodes standard base64 and
 * `SignedUrlMiddleware` signs the raw values, so every URL built from the
 * guide failed. The examples now live in `resources/docs/signed-urls/`, the
 * guide renders those files, and this test runs the PHP file.
 */
class SignedUrlGuideTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Case "unicode, hidden layer and format" of the shared SDK contract
     * vectors (`snapsbrew-sdks/_contract/signed-url-vectors.json`). Its base64
     * holds `/`, so a base64url encoder or a signer that reads URL-encoded
     * values gives another signature.
     */
    private const VECTOR_KEY = 'test-signing-key-0123456789'; // gitleaks:allow (public test vector)

    private const VECTOR_TEMPLATE_ID = '044ec825-ee2f-4d06-bb77-98663e27f529';

    private const VECTOR_VARIANTS_JSON = '[{"layer":"headline","text":"¿Este sí es tu Hawkeye? 🏹"},{"layer":"badge","hidden":true},{"layer":"bg","background_color":"#f0573f"}]';

    private const VECTOR_VARIANTS = 'W3sibGF5ZXIiOiJoZWFkbGluZSIsInRleHQiOiLCv0VzdGUgc8OtIGVzIHR1IEhhd2tleWU/IPCfj7kifSx7ImxheWVyIjoiYmFkZ2UiLCJoaWRkZW4iOnRydWV9LHsibGF5ZXIiOiJiZyIsImJhY2tncm91bmRfY29sb3IiOiIjZjA1NzNmIn1d';

    private const VECTOR_SIGNATURE = 'b5b47072dae2cfe45f03ea4e6f6f453e48a43be381fbf9cbf0adf1efd748713d';

    protected function setUp(): void
    {
        parent::setUp();

        require_once resource_path('docs/signed-urls/sign.php');
    }

    public function test_the_php_example_reproduces_a_contract_vector()
    {
        $url = snapsbrew_signed_url(
            'https://snapsbrew.example/',
            self::VECTOR_KEY,
            self::VECTOR_TEMPLATE_ID,
            json_decode(self::VECTOR_VARIANTS_JSON, true),
            ['format' => 'jpg'],
        );

        $this->assertStringStartsWith('https://snapsbrew.example/signed-url/image.jpg?', $url);

        // The `/` in the base64 value is URL-encoded in the link.
        $this->assertStringContainsString('variants='.rawurlencode(self::VECTOR_VARIANTS).'&', $url);

        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame([
            'format' => 'jpg',
            'signature' => self::VECTOR_SIGNATURE,
            'template_id' => self::VECTOR_TEMPLATE_ID,
            'variants' => self::VECTOR_VARIANTS,
        ], collect($query)->sortKeys()->all());
    }

    /**
     * The vector proves the algorithm. This proves the server agrees: a URL
     * from the example renders, and the snap holds the variants it was given.
     */
    public function test_a_url_from_the_php_example_renders_on_the_real_route()
    {
        Storage::fake('public');
        $this->app->bind(SnapRenderer::class, FakeRenderer::class);

        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $template = Template::factory()->create(['user_id' => $user->id, 'company_id' => $company->id]);
        $key = Str::random(40);
        ApiKey::factory()->signedUrl()->create(['user_id' => $user->id, 'company_id' => $company->id, 'key' => $key]);

        $variants = [['layer' => 'headline', 'text' => '¿Hola? >>~']];

        // This text gives base64 with `+`, `/` and `=`. Each one breaks a
        // different mistake: base64url, a missing URL encoding, and padding.
        $encoded = base64_encode(json_encode($variants, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        foreach (['+', '/', '='] as $character) {
            $this->assertStringContainsString($character, $encoded);
        }

        $url = snapsbrew_signed_url(config('app.url'), $key, $template->uuid, $variants);

        $this->get(substr($url, strlen(rtrim(config('app.url'), '/'))))->assertRedirect();

        $snap = Snap::query()->where('template_id', $template->id)->sole();
        $this->assertSame($variants, json_decode($snap->variants, true));
    }

    public function test_the_guide_shows_each_example_file_and_no_base64url_code()
    {
        $response = $this->get('/documentation/guides/signed-urls')->assertOk();

        foreach (['sign.php', 'sign.mjs', 'sign.py', 'sign.rb'] as $file) {
            $response->assertSee(file_get_contents(resource_path('docs/signed-urls/'.$file)));
        }

        $response->assertSee('standard base64');
        $response->assertDontSee("strtr(base64_encode(\$variants), '+/', '-_')", false);
        $response->assertDontSee('Base64url-encoded');
    }
}
