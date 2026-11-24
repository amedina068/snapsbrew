<?php

namespace Tests\Support;

use App\Contracts\EditorAssetTransport;
use App\ValueObjects\EditorAssetHttpResponse;

class FakeEditorAssetTransport implements EditorAssetTransport
{
    /** @var array<int, EditorAssetHttpResponse> */
    public array $responses;

    /** @var array<int, array{url:string,destination:array{host:string,port:int,ip:string}}> */
    public array $requests = [];

    /** @param array<int, EditorAssetHttpResponse> $responses */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function get(string $url, array $destination): EditorAssetHttpResponse
    {
        $this->requests[] = compact('url', 'destination');

        return array_shift($this->responses) ?? new EditorAssetHttpResponse(500, [], '');
    }
}
