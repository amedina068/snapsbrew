<?php

namespace App\Support;

/**
 * Who can create an account on this instance.
 *
 * The owner sets the mode in the admin area, and the database value wins.
 * When the database has no value, `REGISTRATION_ENABLED` gives the mode.
 * A person with a pending company invitation can sign up in both modes.
 */
class Registration
{
    /** Anyone can sign up and gets a new company. */
    public const OPEN = 'open';

    /** Only a person with a company invitation can sign up. */
    public const INVITE_ONLY = 'invite_only';

    public const MODES = [self::OPEN, self::INVITE_ONLY];

    /** The key of the mode in the `instance_settings` table. */
    public const SETTING = 'registration_mode';

    public static function mode(): string
    {
        $stored = app(InstanceSettings::class)->get(self::SETTING);

        return in_array($stored, self::MODES, true) ? $stored : self::defaultMode();
    }

    public static function isOpen(): bool
    {
        return self::mode() === self::OPEN;
    }

    public static function setMode(string $mode): void
    {
        if (! in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException("Unknown registration mode [{$mode}].");
        }

        app(InstanceSettings::class)->set(self::SETTING, $mode);
    }

    /** The mode that `REGISTRATION_ENABLED` gives. */
    public static function defaultMode(): string
    {
        return config('snapsbrew.registration_enabled') ? self::OPEN : self::INVITE_ONLY;
    }
}
