<?php

namespace App\Mcp\Admin;

/**
 * Removes secrets from text that an instance-admin tool returns.
 *
 * It removes the values of the secret config keys (APP_KEY, the database and
 * mail passwords, the renderer token), bearer tokens, Sanctum token values,
 * and the values of `password`, `token`, `secret`, `signature` and `key`
 * parameters.
 */
trait RedactsSecrets
{
    protected function redact(string $text): string
    {
        $secrets = array_filter(array_map('strval', [
            config('app.key'),
            config('database.connections.mysql.password'),
            config('database.connections.mariadb.password'),
            config('database.connections.pgsql.password'),
            config('database.redis.default.password'),
            config('mail.mailers.smtp.password'),
            config('services.browserless.token'),
            config('services.ses.secret'),
            config('filesystems.disks.s3.secret'),
            config('passport.private_key'),
        ]), fn (string $value) => strlen($value) >= 6);

        if ($secrets !== []) {
            $text = str_replace($secrets, '[redacted]', $text);
        }

        return (string) preg_replace([
            '/(Bearer\s+)[A-Za-z0-9._~+\/=|-]+/i',
            '/\b\d+\|[A-Za-z0-9]{20,}/',
            '/base64:[A-Za-z0-9+\/=]{20,}/',
            '/((?:password|passwd|pwd|token|secret|signature|api_?key|key)["\']?\s*[=:]\s*["\']?)[^\s"\'&,;]+/i',
            '/(-----BEGIN [A-Z ]+-----)[\s\S]*?(-----END [A-Z ]+-----)/',
        ], [
            '$1[redacted]',
            '[redacted]',
            '[redacted]',
            '$1[redacted]',
            '$1[redacted]$2',
        ], $text);
    }
}
