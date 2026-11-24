<?php

namespace App\Support;

/** The two derived facts a failed attempt keeps about its exception. The message itself is never stored. */
final class CreativePackageDiagnostic
{
    /**
     * The class name cut to the column width on a character boundary. An
     * anonymous class name embeds the path of the file that declared it, so
     * only the named parent and the `@anonymous` marker stay.
     */
    public static function className(\Throwable $exception): string
    {
        $class = $exception::class;
        if (str_contains($class, '@anonymous')) {
            $class = strstr($class, '@anonymous', true).'@anonymous';
        }

        return mb_strcut($class, 0, CreativePackageContract::DIAGNOSTIC_CLASS_BYTES, 'UTF-8');
    }

    /** A keyed hash of the message. Equal messages give one value, and the value alone does not confirm a guessed message. */
    public static function fingerprint(string $message): string
    {
        return hash_hmac('sha256', $message, (string) config('app.key'));
    }
}
