<?php

namespace Tests\Unit;

use Tests\TestCase;

class LoggingConfigTest extends TestCase
{
    private array $saved = [];

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            $this->setEnv($name, $value);
        }

        parent::tearDown();
    }

    public function test_the_stack_has_no_rollbar_channel_without_a_token()
    {
        $this->swapEnv('ROLLBAR_TOKEN', null);

        $config = require config_path('logging.php');

        $this->assertSame(['daily'], $config['channels']['stack']['channels']);
    }

    public function test_the_stack_adds_rollbar_when_a_token_is_set()
    {
        $this->swapEnv('ROLLBAR_TOKEN', 'test-token');

        $config = require config_path('logging.php');

        $this->assertSame(['daily', 'rollbar'], $config['channels']['stack']['channels']);
    }

    private function swapEnv(string $name, ?string $value): void
    {
        $this->saved[$name] = $_SERVER[$name] ?? $_ENV[$name] ?? (getenv($name) === false ? null : getenv($name));
        $this->setEnv($name, $value);
    }

    private function setEnv(string $name, ?string $value): void
    {
        if ($value === null) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);

            return;
        }

        $_SERVER[$name] = $_ENV[$name] = $value;
        putenv("{$name}={$value}");
    }
}
