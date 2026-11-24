<?php

namespace Tests\Unit;

use App\Support\ApiDocs\ApiSpec;
use App\Support\ApiDocs\MarkdownGenerator;
use App\Support\ApiDocs\OpenApiGenerator;
use Tests\TestCase;

class ApiSpecBaseUrlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://images.example.org',
            'app.name' => 'Acme Images',
            'snapsbrew.support_email' => 'help@example.org',
        ]);
    }

    public function test_the_spec_names_the_url_of_this_instance()
    {
        $this->assertSame('https://images.example.org/api', ApiSpec::info()['baseUrl']);
        $this->assertSame('help@example.org', ApiSpec::info()['contactEmail']);
        $this->assertSame('Acme Images API', ApiSpec::info()['title']);
    }

    public function test_no_generated_surface_names_the_hosted_domain()
    {
        $surfaces = [
            json_encode(ApiSpec::groups()),
            json_encode(ApiSpec::schemas()),
            app(OpenApiGenerator::class)->toYaml(),
            app(MarkdownGenerator::class)->full(),
        ];

        foreach ($surfaces as $surface) {
            $this->assertStringNotContainsString('snapsbrew.com', $surface);
        }

        $this->assertStringContainsString('https://images.example.org/api/v1/snaps', $surfaces[2]);
    }

    public function test_the_support_email_falls_back_to_the_mail_sender()
    {
        config(['snapsbrew.support_email' => null, 'mail.from.address' => 'robot@example.org']);

        $this->assertSame('robot@example.org', ApiSpec::info()['contactEmail']);
    }
}
