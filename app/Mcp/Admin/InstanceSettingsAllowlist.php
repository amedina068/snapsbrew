<?php

namespace App\Mcp\Admin;

use App\Support\Registration;

/**
 * The instance settings that the instance-admin MCP server can read and
 * change. Each key maps to its allowed values. A setting that is not in this
 * list stays in the admin area or in the environment.
 */
final class InstanceSettingsAllowlist
{
    public const KEYS = [
        Registration::SETTING => Registration::MODES,
    ];

    /**
     * @return array<string, array{value: string, allowed: list<string>}>
     */
    public static function current(): array
    {
        return [
            Registration::SETTING => ['value' => Registration::mode(), 'allowed' => Registration::MODES],
        ];
    }

    public static function set(string $key, string $value): void
    {
        match ($key) {
            Registration::SETTING => Registration::setMode($value),
            default => throw new \InvalidArgumentException("The setting [{$key}] is not in the allowlist."),
        };
    }
}
