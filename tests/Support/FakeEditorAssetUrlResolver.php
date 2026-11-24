<?php

namespace Tests\Support;

use App\Contracts\EditorAssetUrlResolver;
use RuntimeException;

class FakeEditorAssetUrlResolver implements EditorAssetUrlResolver
{
    /** @var array<int, array{host:string,port:int,ip:string}|RuntimeException> */
    public array $results;

    /** @var array<int, string> */
    public array $urls = [];

    /** @param array<int, array{host:string,port:int,ip:string}|RuntimeException> $results */
    public function __construct(array $results)
    {
        $this->results = $results;
    }

    public function resolve(string $url): array
    {
        $this->urls[] = $url;
        $result = array_shift($this->results);
        if ($result instanceof RuntimeException) {
            throw $result;
        }

        return $result ?? throw new RuntimeException('asset_url_rejected');
    }
}
