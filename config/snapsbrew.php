<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Preview Watermark
    |--------------------------------------------------------------------------
    |
    | When this is true, every preview of the REST API and the MCP server shows a
    | "PREVIEW" overlay. A final render never shows it. Set it to false on a
    | private instance where nobody can take a preview as a final image.
    |
    */

    'preview_watermark' => (bool) env('SNAPSBREW_PREVIEW_WATERMARK', true),

    /*
    |--------------------------------------------------------------------------
    | Registration
    |--------------------------------------------------------------------------
    |
    | The default registration mode. False is invite only: only a person with
    | a company invitation can create an account. True is open. The owner can
    | change the mode in the admin area, and that value wins. Read the mode
    | with `App\Support\Registration::isOpen()`, not with this key. Create the
    | first account on the `/setup` page or with `php artisan snapsbrew:install`.
    |
    */

    'registration_enabled' => (bool) env('REGISTRATION_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Legal Pages
    |--------------------------------------------------------------------------
    |
    | The app ships no privacy policy and no terms of service. Set these to
    | the pages of your instance. `llms.txt` links them when they are set.
    |
    */

    'privacy_url' => env('SNAPSBREW_PRIVACY_URL'),

    'terms_url' => env('SNAPSBREW_TERMS_URL'),

    /*
    |--------------------------------------------------------------------------
    | Support Email
    |--------------------------------------------------------------------------
    |
    | The address the API docs show for questions. When it is empty, the docs
    | show MAIL_FROM_ADDRESS.
    |
    */

    'support_email' => env('SNAPSBREW_SUPPORT_EMAIL'),

    /*
    |--------------------------------------------------------------------------
    | Signed URL Key Cache
    |--------------------------------------------------------------------------
    |
    | The signed URL route checks the signature on every request, also when it
    | serves a cached snap. It caches the valid keys of each user for this many
    | seconds. A change to a key or to its access token clears the cache at
    | once, so this value only limits a change made outside the app.
    |
    */

    'signed_url_key_cache_seconds' => (int) env('SNAPSBREW_SIGNED_URL_KEY_CACHE_SECONDS', 60),

    // Requests per minute from one IP to the public signed URL route. A link
    // preview crawler fetches `og:image` URLs in bursts from a few IPs, and a
    // cached image costs one database read, so the limit is high.
    'signed_url_requests_per_ip' => (int) env('SNAPSBREW_SIGNED_URL_REQUESTS_PER_IP', 600),

    /*
    |--------------------------------------------------------------------------
    | Embedded Form Rate Limits
    |--------------------------------------------------------------------------
    |
    | The embedded form page and its submit route are public. Each submit
    | renders a snap on the account of the form owner. These limits are per
    | minute. A request over a limit gets status 429.
    |
    | The per IP limits read the client IP. If a reverse proxy is in front of
    | the app, set TRUSTED_PROXIES. If you do not, every visitor has the IP
    | of the proxy and shares one limit.
    |
    */

    'embedded_form_rate_limits' => [
        'submits_per_ip' => (int) env('SNAPSBREW_EMBEDDED_FORM_SUBMITS_PER_IP', 10),
        'submits_per_form' => (int) env('SNAPSBREW_EMBEDDED_FORM_SUBMITS_PER_FORM', 60),
        'views_per_ip' => (int) env('SNAPSBREW_EMBEDDED_FORM_VIEWS_PER_IP', 60),
        'views_per_form' => (int) env('SNAPSBREW_EMBEDDED_FORM_VIEWS_PER_FORM', 600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Invitations
    |--------------------------------------------------------------------------
    |
    | The maximum number of invitation mails that one company can send in 24
    | hours. The account page and the onboarding wizard share this count.
    |
    */

    'invitations_per_company_per_day' => (int) env('SNAPSBREW_INVITATIONS_PER_COMPANY_PER_DAY', 20),

    /*
    |--------------------------------------------------------------------------
    | Spreadsheet imports
    |--------------------------------------------------------------------------
    |
    | The maximum number of data rows in one import file. Each row queues one
    | snap. A file with more rows, on any sheet, gets a validation error
    | before the app loads it.
    |
    */

    'import_max_rows' => (int) env('SNAPSBREW_IMPORT_MAX_ROWS', 5000),

    /*
    |--------------------------------------------------------------------------
    | Version
    |--------------------------------------------------------------------------
    |
    | The release of this instance. The Docker image sets SNAPSBREW_VERSION at
    | build time. `snapsbrew:status` and the instance-admin MCP server show it.
    |
    */

    'version' => env('SNAPSBREW_VERSION', 'dev'),

];
