<?php

namespace App\Services;

use App\Contracts\EditorAssetTransport;
use App\Models\EditorAsset;
use App\ValueObjects\EditorAssetHttpResponse;

class CurlEditorAssetTransport implements EditorAssetTransport
{
    public function get(string $url, array $destination): EditorAssetHttpResponse
    {
        $headers = [];
        $bytes = '';
        $overflow = false;
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '',
            CURLOPT_HTTPHEADER => ['Accept: image/jpeg,image/png,image/webp', 'Accept-Encoding: identity'],
            CURLOPT_RESOLVE => ["{$destination['host']}:{$destination['port']}:{$destination['ip']}"],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$headers): int {
                if (str_contains($header, ':')) {
                    [$name, $value] = explode(':', $header, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }

                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$bytes, &$overflow): int {
                if (strlen($bytes) + strlen($chunk) > EditorAsset::MAX_BYTES) {
                    $overflow = true;

                    return 0;
                }
                $bytes .= $chunk;

                return strlen($chunk);
            },
        ]);
        $result = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $timedOut = curl_errno($handle) === CURLE_OPERATION_TIMEDOUT;
        curl_close($handle);

        return new EditorAssetHttpResponse($status, $headers, $result === false ? '' : $bytes, $overflow, $timedOut);
    }
}
