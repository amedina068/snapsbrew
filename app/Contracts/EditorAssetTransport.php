<?php

namespace App\Contracts;

use App\ValueObjects\EditorAssetHttpResponse;

interface EditorAssetTransport
{
    /** @param array{host:string,port:int,ip:string} $destination */
    public function get(string $url, array $destination): EditorAssetHttpResponse;
}
