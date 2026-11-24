<?php

namespace App\Contracts;

interface EditorAssetUrlResolver
{
    /** @return array{host:string,port:int,ip:string} */
    public function resolve(string $url): array;
}
