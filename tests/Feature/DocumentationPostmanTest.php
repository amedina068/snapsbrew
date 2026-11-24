<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentationPostmanTest extends TestCase
{
    use RefreshDatabase;

    public function test_postman_collection_download_returns_json()
    {
        $response = $this->get('/documentation/postman-collection');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertDownload('snapsbrew-api.postman_collection.json');
    }
}
