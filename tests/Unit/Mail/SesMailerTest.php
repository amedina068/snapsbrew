<?php

namespace Tests\Unit\Mail;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Illuminate\Mail\Transport\SesTransport;
use Illuminate\Mail\Transport\SesV2Transport;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Guards the Amazon SES mailers. The SES credentials can differ from the S3
 * credentials, because one IAM user seldom has both permissions. These tests
 * send through the real transport with a mocked AWS handler, so they prove
 * the request that SES receives and not only the configuration.
 */
class SesMailerTest extends TestCase
{
    public function test_the_ses_mailer_sends_through_the_ses_client_with_the_configuration_set()
    {
        config([
            'services.ses.key' => 'test-key',
            'services.ses.secret' => 'test-secret',
            'services.ses.region' => 'eu-west-1',
            'mail.mailers.ses.options' => ['ConfigurationSetName' => 'snapsbrew-events'],
        ]);

        $transport = Mail::mailer('ses')->getSymfonyTransport();
        $this->assertInstanceOf(SesTransport::class, $transport);
        $this->assertSame('eu-west-1', $transport->ses()->getRegion());

        $command = $this->sendAndCapture($transport, 'ses');

        $this->assertSame('SendRawEmail', $command->getName());
        $this->assertSame('snapsbrew-events', $command['ConfigurationSetName']);
        $this->assertSame('ops@snapsbrew.test', $command['Source']);
    }

    public function test_the_ses_v2_mailer_sends_through_the_ses_v2_client_with_the_configuration_set()
    {
        config([
            'services.ses.key' => 'test-key',
            'services.ses.secret' => 'test-secret',
            'services.ses.region' => 'eu-west-1',
            'mail.mailers.ses-v2.options' => ['ConfigurationSetName' => 'snapsbrew-events'],
        ]);

        $transport = Mail::mailer('ses-v2')->getSymfonyTransport();
        $this->assertInstanceOf(SesV2Transport::class, $transport);
        $this->assertSame('eu-west-1', $transport->ses()->getRegion());

        $command = $this->sendAndCapture($transport, 'ses-v2');

        $this->assertSame('SendEmail', $command->getName());
        $this->assertSame('snapsbrew-events', $command['ConfigurationSetName']);
        $this->assertStringContainsString('From: ops@snapsbrew.test', $command['Content']['Raw']['Data']);
    }

    public function test_the_mailers_send_no_configuration_set_when_none_is_configured()
    {
        $config = $this->loadConfig('mail.php', ['AWS_SES_CONFIGURATION_SET' => '']);

        $this->assertSame([], $config['mailers']['ses']['options']);
        $this->assertSame([], $config['mailers']['ses-v2']['options']);
    }

    public function test_the_mailers_read_the_configuration_set_from_the_environment()
    {
        $config = $this->loadConfig('mail.php', ['AWS_SES_CONFIGURATION_SET' => 'snapsbrew-events']);

        $this->assertSame(['ConfigurationSetName' => 'snapsbrew-events'], $config['mailers']['ses']['options']);
        $this->assertSame(['ConfigurationSetName' => 'snapsbrew-events'], $config['mailers']['ses-v2']['options']);
    }

    public function test_the_ses_specific_credentials_win_over_the_shared_aws_credentials()
    {
        $ses = $this->loadConfig('services.php', [
            'AWS_ACCESS_KEY_ID' => 's3-key',
            'AWS_SECRET_ACCESS_KEY' => 's3-secret',
            'AWS_DEFAULT_REGION' => 'us-east-1',
            'AWS_SES_ACCESS_KEY_ID' => 'ses-key',
            'AWS_SES_SECRET_ACCESS_KEY' => 'ses-secret',
            'AWS_SES_REGION' => 'eu-west-1',
        ])['ses'];

        $this->assertSame('ses-key', $ses['key']);
        $this->assertSame('ses-secret', $ses['secret']);
        $this->assertSame('eu-west-1', $ses['region']);
    }

    public function test_empty_ses_credentials_fall_back_to_the_shared_aws_credentials()
    {
        // .env.example lists the SES keys with empty values, so a present but
        // empty key must fall back the same way as an absent key.
        $ses = $this->loadConfig('services.php', [
            'AWS_ACCESS_KEY_ID' => 's3-key',
            'AWS_SECRET_ACCESS_KEY' => 's3-secret',
            'AWS_DEFAULT_REGION' => 'us-east-2',
            'AWS_SES_ACCESS_KEY_ID' => '',
            'AWS_SES_SECRET_ACCESS_KEY' => '',
            'AWS_SES_REGION' => '',
        ])['ses'];

        $this->assertSame('s3-key', $ses['key']);
        $this->assertSame('s3-secret', $ses['secret']);
        $this->assertSame('us-east-2', $ses['region']);
    }

    private function sendAndCapture($transport, string $mailer): CommandInterface
    {
        $captured = null;
        $transport->ses()->getHandlerList()->setHandler(
            new MockHandler([
                function (CommandInterface $command) use (&$captured) {
                    $captured = $command;

                    return new Result(['MessageId' => 'test-message-id']);
                },
            ])
        );

        Mail::mailer($mailer)->raw('Body', function ($message) {
            $message->from('ops@snapsbrew.test')->to('customer@example.com')->subject('Test');
        });

        $this->assertNotNull($captured, 'The mailer did not call SES.');

        return $captured;
    }

    /**
     * Evaluate a config file against the given environment values, then
     * restore the environment.
     */
    private function loadConfig(string $file, array $env): array
    {
        $previous = [];
        foreach ($env as $key => $value) {
            $previous[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null, getenv($key)];
            $_SERVER[$key] = $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }

        try {
            return require config_path($file);
        } finally {
            foreach ($previous as $key => [$server, $envValue, $putenv]) {
                if ($server === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $server;
                }
                if ($envValue === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $envValue;
                }
                putenv($putenv === false ? $key : "{$key}={$putenv}");
            }
        }
    }
}
