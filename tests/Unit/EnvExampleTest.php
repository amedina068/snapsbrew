<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every variable that the app code or an app config file reads must appear in
 * .env.example, so a person who self-hosts can see every setting.
 */
class EnvExampleTest extends TestCase
{
    /** Framework variables that the Laravel docs describe. The example file leaves them out. */
    private const FRAMEWORK = [
        'ABLY_KEY', 'ASSET_URL', 'AWS_SESSION_TOKEN', 'BCRYPT_ROUNDS', 'DATABASE_URL', 'DB_FOREIGN_KEYS', 'DB_SOCKET',
        'DYNAMODB_CACHE_TABLE', 'DYNAMODB_ENDPOINT', 'ENABLE_MEDIA_LIBRARY_VAPOR_UPLOADS', 'LOG_SLACK_WEBHOOK_URL',
        'LOG_STDERR_FORMATTER', 'MAILGUN_DOMAIN', 'MAILGUN_ENDPOINT', 'MAILGUN_SECRET', 'MAIL_LOG_CHANNEL',
        'MEMCACHED_HOST', 'MEMCACHED_PASSWORD', 'MEMCACHED_PERSISTENT_ID', 'MEMCACHED_PORT', 'MEMCACHED_USERNAME',
        'MYSQL_ATTR_SSL_CA', 'PAPERTRAIL_PORT', 'PAPERTRAIL_URL', 'POSTMARK_TOKEN', 'QUEUE_FAILED_DRIVER',
        'REDIS_CACHE_DB', 'REDIS_CLIENT', 'REDIS_CLUSTER', 'REDIS_DB', 'REDIS_QUEUE', 'REDIS_URL',
        'SANCTUM_STATEFUL_DOMAINS', 'SESSION_CONNECTION', 'SESSION_DOMAIN', 'SESSION_SECURE_COOKIE', 'SESSION_STORE',
        'SQS_PREFIX', 'SQS_QUEUE', 'SQS_SUFFIX', 'PUSHER_APP_ID', 'PUSHER_APP_KEY', 'PUSHER_APP_SECRET',
        'PUSHER_APP_CLUSTER', 'PUSHER_HOST', 'PUSHER_PORT', 'PUSHER_SCHEME',
    ];

    public function test_env_example_lists_every_variable_the_code_reads()
    {
        $root = dirname(__DIR__, 2);
        $read = [];

        foreach (['app', 'config', 'routes', 'database', 'bootstrap'] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$root}/{$directory}"));
            foreach ($files as $file) {
                if ($file->getExtension() === 'php' && preg_match_all("/env\\('([A-Z0-9_]+)'/", file_get_contents($file->getPathname()), $matches)) {
                    array_push($read, ...$matches[1]);
                }
            }
        }

        preg_match_all('/^#?\s*([A-Z0-9_]+)=/m', file_get_contents("{$root}/.env.example"), $listed);

        $missing = array_values(array_diff(array_unique($read), $listed[1], self::FRAMEWORK));
        sort($missing);

        $this->assertSame([], $missing, 'Add these variables to .env.example.');
    }
}
