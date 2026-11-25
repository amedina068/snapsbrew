<?php

namespace App\Support;

/**
 * Tells if the mail of this instance leaves the server.
 *
 * The `log` mailer writes each mail to the log file. A new instance uses it
 * until the owner sets up SMTP, SES or another mailer. Then the pages that
 * send a link by mail also show the link, so a person can send it.
 */
class MailDelivery
{
    public static function isConfigured(): bool
    {
        return config('mail.default') !== 'log';
    }
}
