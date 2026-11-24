<?php

namespace Tests\Unit\Models;

use App\Models\ApiKey;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_key_belongs_to_company()
    {
        $apiKey = ApiKey::factory()->create();

        $this->assertInstanceOf(Company::class, $apiKey->company);
    }

    public function test_key_is_encrypted()
    {
        $apiKey = ApiKey::factory()->create(['key' => 'my-secret-key']);

        // The raw database value should be encrypted (not the plain text)
        $rawValue = \DB::table('api_keys')->where('id', $apiKey->id)->value('key');
        $this->assertNotEquals('my-secret-key', $rawValue);

        // But the model attribute should decrypt it
        $this->assertEquals('my-secret-key', $apiKey->fresh()->key);
    }

    public function test_type_signed_url_constant()
    {
        $this->assertEquals('signed_url', ApiKey::TYPE_SIGNED_URL);
    }

    public function test_scope_signed_url()
    {
        ApiKey::factory()->create(['type' => ApiKey::TYPE_SIGNED_URL]);
        ApiKey::factory()->create(['type' => 'other']);

        $signedUrlKeys = ApiKey::signedUrl()->get();

        $this->assertCount(1, $signedUrlKeys);
        $this->assertEquals(ApiKey::TYPE_SIGNED_URL, $signedUrlKeys->first()->type);
    }
}
