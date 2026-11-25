<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    // SES reads its own AWS_SES_* keys first, because the IAM user for S3
    // seldom has SES permissions. Coalesce instead of an env() default: the
    // keys are present but empty in .env.example, and env()'s default only
    // applies when the key is absent. If no key is set, the AWS SDK reads its
    // default credential chain (for example an instance or task role).
    'ses' => [
        'key' => env('AWS_SES_ACCESS_KEY_ID') ?: env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SES_SECRET_ACCESS_KEY') ?: env('AWS_SECRET_ACCESS_KEY'),
        'token' => env('AWS_SESSION_TOKEN'),
        'region' => env('AWS_SES_REGION') ?: env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'render' => [
        'driver' => env('RENDER_DRIVER', 'browsershot'),
        // The app origin that the render browser can reach, for example
        // http://127.0.0.1:8080 for the Chromium in the image, or
        // http://web:8080 for browserless in Docker Compose. It is used only
        // when MEDIA_DISK is "public". Empty keeps the APP_URL asset URLs.
        'asset_url' => env('RENDER_ASSET_URL'),
        // Embed the Google fonts of a snap in its HTML, so a render needs no
        // internet access. The starter fonts ship in resources/fonts. The
        // first render with another font downloads it to font_cache_path.
        'embed_fonts' => (bool) env('RENDER_EMBED_FONTS', true),
        'font_downloads' => (bool) env('RENDER_FONT_DOWNLOADS', true),
        'font_cache_path' => storage_path('app/fonts'),
    ],

    // Empty paths are found on PATH (and in /usr/local/bin). The node_modules
    // folder that holds Puppeteer saves a slow `npm root -g` call per render.
    // An empty no_sandbox turns the Chrome sandbox off only in a container or
    // as root.
    'browsershot' => [
        'node_path' => env('BROWSERSHOT_NODE_PATH'),
        'npm_path' => env('BROWSERSHOT_NPM_PATH'),
        'node_modules_path' => env('BROWSERSHOT_NODE_MODULES_PATH', base_path('node_modules')),
        'chrome_path' => env('BROWSERSHOT_CHROME_PATH'),
        'no_sandbox' => env('BROWSERSHOT_NO_SANDBOX'),
        'instance' => env('BROWSERSHOT_INSTANCE', ''),
    ],

    // The free public tools (`POST /api/v1/tools/*`). They return 404 until
    // `enabled` is true. `user_id` is the account that owns the screenshots
    // of the screenshot tool, because that tool has no logged-in user.
    // `templates` holds the uuid of the one template that each tool renders.
    // A tool without a template uuid returns 503. The request cannot choose
    // the template.
    'free_tools' => [
        'enabled' => (bool) env('SNAPSBREW_FREE_TOOLS_ENABLED', false),
        'user_id' => (int) env('SNAPSBREW_FREE_TOOLS_USER_ID', 1),
        'templates' => [
            'certificate' => env('SNAPSBREW_FREE_TOOLS_CERTIFICATE_TEMPLATE'),
            'testimonial' => env('SNAPSBREW_FREE_TOOLS_TESTIMONIAL_TEMPLATE'),
            'twitter_graph' => env('SNAPSBREW_FREE_TOOLS_TWITTER_GRAPH_TEMPLATE'),
        ],
        'requests_per_minute_per_ip' => (int) env('SNAPSBREW_FREE_TOOLS_REQUESTS_PER_MINUTE_PER_IP', 5),
        'requests_per_day_per_ip' => (int) env('SNAPSBREW_FREE_TOOLS_REQUESTS_PER_DAY_PER_IP', 50),
    ],

    'browserless' => [
        'url' => env('BROWSERLESS_URL'),
        'token' => env('BROWSERLESS_TOKEN'),
        // Seconds for one attempt. The renderer tries 3 times, and Horizon
        // kills the job at 60 seconds, so 3 attempts must fit in 60 seconds.
        'timeout' => (int) env('BROWSERLESS_TIMEOUT', 15),
    ],

    // Optional. The docs pages can render their own OG / social card through
    // a signed URL of this instance. `signed_url_key` is a signed-URL API key
    // (Account → Developers) and `og_template` is the uuid of a template with
    // `badge`, `headline` and `subhead` layers. When either is empty, the
    // pages use a static image.
    'snapsbrew' => [
        'signed_url_key' => env('SNAPSBREW_SIGNED_URL_KEY'),
        'og_template' => env('SNAPSBREW_OG_TEMPLATE'),
        'og_base' => env('SNAPSBREW_OG_BASE', env('APP_URL', 'http://localhost')),
    ],

];
