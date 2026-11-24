<?php

namespace App\Support;

/**
 * Single source of truth for PER-PLATFORM publishing rules — what a platform
 * does to an image after you upload it, and what that forces the design to do.
 *
 * Read the three guidance catalogs as one set, split by the question they answer:
 *   - `App\Support\DesignGuide`       — how to compose layers well (any canvas).
 *   - `App\Support\PlatformGuide`     — what Instagram/X/LinkedIn do to the result (this class).
 *   - `App\Support\ImagePurposeGuide` — what the image is FOR (link preview, carousel, avatar).
 *
 * Dimensions are NOT stored here. Every entry names preset `size` values and
 * `App\Support\TemplateSizes` resolves them, because a second list of pixel
 * numbers drifts from the first by the second edit. `PlatformGuideTest` asserts
 * that every platform key and every named preset still exists.
 *
 * The specs below were reviewed against platform guidance in SPECS_REVIEWED.
 * Platforms change their crops and their limits — re-check before you treat a
 * number here as current.
 */
class PlatformGuide
{
    /**
     * The month the published specs below were last checked.
     */
    public const SPECS_REVIEWED = '2026-09';

    /**
     * What a caller is likely to type, mapped to a platform key. Matching is
     * case-insensitive, and a phrase is matched word by word, so "instagram
     * story" and "Open Graph" both resolve.
     *
     * @var array<string, string>
     */
    public const ALIASES = [
        'insta' => 'instagram',
        'ig' => 'instagram',
        'reel' => 'instagram',
        'reels' => 'instagram',
        'story' => 'instagram',
        'stories' => 'instagram',
        'twitter' => 'x',
        'tweet' => 'x',
        'fb' => 'facebook',
        'meta' => 'facebook',
        'li' => 'linkedin',
        'pin' => 'pinterest',
        'pins' => 'pinterest',
        'yt' => 'youtube',
        'shorts' => 'youtube',
        'snap' => 'snapchat',
        'wa' => 'whatsapp',
        'og' => 'web',
        'opengraph' => 'web',
        'graph' => 'web',
        'email' => 'web',
        'blog' => 'web',
        'website' => 'web',
        'site' => 'web',
        'ad' => 'ads',
        'banner' => 'ads',
        'display' => 'ads',
        'printing' => 'print',
        'flyer' => 'print',
    ];

    /**
     * One entry per platform key in `TemplateSizes::PLATFORMS`.
     *
     * `start_here` names the preset to reach for when the human says only
     * "make me something for <platform>". `ui_safe_zones` gives DIRECTIONAL
     * insets where the platform paints its own controls over the image — those
     * supersede the uniform `safe_margin_px` that `TemplateSizes` reports.
     *
     * @var array<string, array<string, mixed>>
     */
    private const GUIDES = [
        'instagram' => [
            'start_here' => 'instagram_post',
            'why' => 'A 4:5 portrait post fills the most vertical space the feed will give a single image. Square still works; landscape gives away half the screen.',
            'cropping' => 'The feed accepts 1.91:1 through 4:5 and crops anything outside that range. The profile grid previews a 3:4 crop of the same file, so a 4:5 post loses roughly 35px from each side there — keep headlines and logos off the left and right edges.',
            'ui_safe_zones' => [
                ['size' => 'instagram_story', 'top_px' => 250, 'bottom_px' => 340, 'left_px' => 60, 'right_px' => 60, 'covers' => 'Profile row and the close button on top; the reply bar, link sticker and share row on the bottom.'],
                ['size' => 'instagram_reel', 'top_px' => 250, 'bottom_px' => 420, 'left_px' => 60, 'right_px' => 180, 'covers' => 'The right rail holds like, comment, share and the audio disc. The caption and the audio ticker sit along the bottom.'],
            ],
            'file' => [
                'formats' => 'JPEG and PNG. Instagram re-encodes every upload to JPEG.',
                'max_file_size' => '30 MB.',
                'upload_width' => 'Export at exactly 1080px wide. Instagram resamples anything else, and a resample is where the artefacts come from.',
                'color_profile' => 'sRGB. A wide-gamut profile is discarded and the colours shift.',
            ],
            'rules' => [
                'One message per frame. Instagram is a scroll, not a page.',
                'Text must read at thumbnail size. On a 1080px canvas that means roughly 40px minimum for anything that matters.',
                'In a carousel every slide must share ONE aspect ratio. A slide with a different ratio is cropped to match the first one.',
                'Stories and Reels: put the message in the middle band. The top and the bottom belong to Instagram.',
                'Alt text is a separate field, not a layer. Write it when you post; do not render the description into the image.',
            ],
            'avoid' => [
                'A feed post taller than 4:5 — the extra height is cropped away, not shown.',
                'Text in the bottom third of a Reel. The caption covers it.',
                'Fine print. If it needs to be small, it belongs in the caption.',
            ],
        ],

        'facebook' => [
            'start_here' => 'facebook_feed',
            'why' => 'A shared link renders the 1.91:1 open-graph card, which is the format most Facebook traffic actually sees. For a native photo post use `facebook_portrait` instead — 4:5 takes more feed space.',
            'cropping' => 'Link cards crop to 1.91:1. A page cover is cropped differently on desktop and on mobile, so only its horizontal centre is guaranteed to survive.',
            'ui_safe_zones' => [
                ['size' => 'facebook_story', 'top_px' => 250, 'bottom_px' => 340, 'left_px' => 60, 'right_px' => 60, 'covers' => 'Profile row on top, reply and action bar on the bottom.'],
                ['size' => 'facebook_cover', 'top_px' => 60, 'bottom_px' => 120, 'left_px' => 220, 'right_px' => 60, 'covers' => 'The page profile picture overlaps the lower-left corner on desktop, and mobile crops the left and right sides.'],
            ],
            'file' => [
                'formats' => 'JPEG and PNG.',
                'max_file_size' => '30 MB for a post. An `og:image` must stay under 8 MB or the crawler drops it.',
                'upload_width' => '1080px or wider for a feed photo; exactly 1200x630 for a link card.',
                'color_profile' => 'sRGB.',
            ],
            'rules' => [
                'A link preview reads `og:image`. Call `image-purpose-guide` with purpose `link_preview` for the meta tags.',
                'An `og:image` smaller than 600x315 renders as a small thumbnail beside the text instead of a full-width card.',
                'The 20 percent text rule was retired, but a text-heavy image still costs reach. Keep the words few and large.',
                'A page cover is read on a phone first. Centre the message.',
            ],
            'avoid' => [
                'Putting the message in the corners of a cover photo.',
                'A link card under 1200px wide — it looks soft on every retina screen.',
            ],
        ],

        'x' => [
            'start_here' => 'twitter_card',
            'why' => 'Most X impressions of a link come from the summary card, not from a native photo. Use `x_post` (16:9) when you attach an image to the post itself.',
            'cropping' => 'X centre-crops a summary card to 2:1 in the timeline, so on a 1200x670 card the top and bottom 35px can disappear. An attached photo is previewed at 16:9 and opens in full only when tapped. Keep the subject in the centre 80 percent either way.',
            'ui_safe_zones' => [
                ['size' => 'twitter_card', 'top_px' => 40, 'bottom_px' => 40, 'left_px' => 60, 'right_px' => 60, 'covers' => 'Not an overlay — the 2:1 timeline crop. A 1200x670 card shows about 1200x600 of itself in the feed.'],
                ['size' => 'x_header', 'top_px' => 40, 'bottom_px' => 40, 'left_px' => 280, 'right_px' => 40, 'covers' => 'The profile photo overlaps the lower-left corner, and the header is cropped vertically on narrow screens.'],
            ],
            'file' => [
                'formats' => 'JPEG, PNG, WebP and GIF.',
                'max_file_size' => '5 MB from mobile, 15 MB from the web client. A card image must stay under 5 MB.',
                'upload_width' => 'Up to 4096x4096. 1600px wide is enough for any timeline.',
                'color_profile' => 'sRGB.',
            ],
            'rules' => [
                'Declare `twitter:card` as `summary_large_image` or X falls back to the small square card.',
                'The card renders about 500px wide in a phone timeline. Anything under 28px on a 1200px canvas is unreadable there.',
                'X drops the image when the card fails to fetch. Serve it over HTTPS from a URL that needs no cookie.',
            ],
            'avoid' => [
                'Text near the top or bottom edge of a card — the 2:1 crop eats it.',
                'A four-image grid where each image carries text. Each one renders at a quarter size.',
            ],
        ],

        'linkedin' => [
            'start_here' => 'linkedin_portrait',
            'why' => 'A 4:5 portrait occupies about twice the mobile feed area of the 1.91:1 landscape card. Use `linkedin_feed` (1200x627) for the link preview, which LinkedIn renders from `og:image`.',
            'cropping' => 'The feed shows portrait posts up to 4:5. A link preview is cropped to 1.91:1. A profile banner is cropped hard on mobile and the profile photo covers its lower-left corner on desktop.',
            'ui_safe_zones' => [
                ['size' => 'linkedin_banner', 'top_px' => 40, 'bottom_px' => 40, 'left_px' => 300, 'right_px' => 40, 'covers' => 'The profile photo sits over the lower-left of the banner on desktop; mobile crops both sides.'],
            ],
            'file' => [
                'formats' => 'JPEG, PNG and WebP.',
                'max_file_size' => 'Keep a feed image under 5 MB. Page images are capped at 3 MB.',
                'upload_width' => '1200px minimum. LinkedIn compresses hard below that.',
                'color_profile' => 'sRGB.',
            ],
            'rules' => [
                'A link preview needs `og:image` at 1200x627 and at least 200px wide, or LinkedIn shows no image at all.',
                'LinkedIn caches a link preview by URL. Change the image URL when you change the image.',
                'The audience reads on a phone between meetings. One claim, large type, no paragraph.',
            ],
            'avoid' => [
                'Dense charts. A chart that needs a legend to read does not survive the feed.',
                'Text in the lower-left of a profile banner.',
            ],
        ],

        'threads' => [
            'start_here' => 'threads_post',
            'why' => 'Threads renders portrait images tallest. 4:5 is the safe portrait that also works when the same asset is cross-posted to Instagram.',
            'cropping' => 'The feed previews a tall image and expands it on tap. Very wide or very tall ratios are letterboxed rather than cropped.',
            'ui_safe_zones' => [],
            'file' => [
                'formats' => 'JPEG, PNG and WebP.',
                'max_file_size' => '8 MB.',
                'upload_width' => 'At least 320px, at most 1440px. 1080px is the practical target.',
                'color_profile' => 'sRGB.',
            ],
            'rules' => [
                'Threads posts carry more text than Instagram ones. Let the image hold one idea and put the detail in the post body.',
                'A post can hold up to 20 images. Same rule as any gallery: one aspect ratio for all of them.',
            ],
            'avoid' => [
                'Reposting a Story frame here. The Threads feed paints no UI over the image, so the empty top and bottom bands read as a mistake.',
            ],
        ],

        'pinterest' => [
            'start_here' => 'pinterest_pin',
            'why' => '2:3 is the ratio Pinterest recommends and the one that fills a feed column without being cropped.',
            'cropping' => 'The feed crops anything taller than about 1:2.1. `pinterest_long_pin` (1000x2100) sits at that limit: it renders in full on the Pin page and is cut off in the feed. Put the hook in the top 1500px of a tall pin.',
            'ui_safe_zones' => [
                ['size' => 'pinterest_pin', 'top_px' => 40, 'bottom_px' => 40, 'left_px' => 40, 'right_px' => 200, 'covers' => 'Nothing overlays a Pin in the feed, but the save button hovers over the top-right corner on desktop.'],
            ],
            'file' => [
                'formats' => 'JPEG and PNG.',
                'max_file_size' => '20 MB on the web uploader. Keep it under 5 MB so the pin loads fast.',
                'upload_width' => '1000px wide is the recommended base.',
                'color_profile' => 'sRGB.',
            ],
            'rules' => [
                'Pinterest is the one platform where a text overlay is expected rather than tolerated. The headline on the pin is how the search result reads.',
                'Put the headline in the top third. The feed truncates tall pins from the bottom.',
                'A pin is a search result. Say what the thing IS, not a clever line.',
            ],
            'avoid' => [
                'A square pin when a 2:3 fits. Square gives away a third of the column.',
                'A tall pin whose payoff is at the bottom.',
            ],
        ],

        'tiktok' => [
            'start_here' => 'tiktok_vertical',
            'why' => 'Full-screen 9:16 is the only ratio that fills a TikTok. Anything else is letterboxed with black bars.',
            'cropping' => 'A photo post (`tiktok_photo`, 3:4 portrait) is shown inside the same 9:16 screen, so the same UI still covers the edges.',
            'ui_safe_zones' => [
                ['size' => 'tiktok_vertical', 'top_px' => 140, 'bottom_px' => 480, 'left_px' => 60, 'right_px' => 180, 'covers' => 'The For You / Following tabs and search on top; the action column (avatar, like, comment, bookmark, share) on the right; the handle, caption and audio ticker along the bottom.'],
                ['size' => 'tiktok_photo', 'top_px' => 140, 'bottom_px' => 400, 'left_px' => 60, 'right_px' => 180, 'covers' => 'The same overlay. A photo post is framed inside the vertical screen.'],
            ],
            'file' => [
                'formats' => 'JPEG and PNG.',
                'max_file_size' => 'Keep it under 10 MB.',
                'upload_width' => '1080px wide.',
                'color_profile' => 'sRGB.',
            ],
            'rules' => [
                'The usable area is roughly the centre 840x1300 of a 1080x1920 frame. Design inside that, not inside the full canvas.',
                'The bottom 480px carries the busiest overlay on any platform. Nothing you need read goes there.',
                'Burn nothing into the frame that the caption already says.',
            ],
            'avoid' => [
                'A logo in the bottom-right. The share icon sits on it.',
                'Centring the subject vertically. The visual centre of the usable area is higher than the centre of the canvas.',
            ],
        ],

        'youtube' => [
            'start_here' => 'youtube_thumbnail',
            'why' => 'The thumbnail is the whole decision. It is the single image that determines whether the video is opened.',
            'cropping' => 'The thumbnail is not cropped, but it renders at about 210x118 on a phone. The duration badge sits over its lower-right corner. A channel banner shows only its centre 1235x338 on a TV.',
            'ui_safe_zones' => [
                ['size' => 'youtube_thumbnail', 'top_px' => 0, 'bottom_px' => 60, 'left_px' => 0, 'right_px' => 220, 'covers' => 'The duration badge covers the lower-right corner. The watch-later and progress overlays also sit along the bottom edge.'],
                ['size' => 'youtube_banner', 'top_px' => 550, 'bottom_px' => 550, 'left_px' => 660, 'right_px' => 660, 'covers' => 'Only the centre 1235x338 is guaranteed. Everything outside it is decoration that a TV or a phone crops away.'],
                ['size' => 'youtube_shorts', 'top_px' => 140, 'bottom_px' => 420, 'left_px' => 60, 'right_px' => 180, 'covers' => 'The same shape of overlay as any vertical feed: tabs on top, action column right, title and channel along the bottom.'],
            ],
            'file' => [
                'formats' => 'JPEG, PNG and GIF.',
                'max_file_size' => '2 MB for a thumbnail. This limit is small and it is enforced.',
                'upload_width' => '1280px wide, 640px minimum.',
                'color_profile' => 'sRGB.',
            ],
            'rules' => [
                'Four words maximum. The title is already written next to the thumbnail — do not repeat it.',
                'One face or one object, large, high contrast. Test it at 210px wide before you ship it.',
                'Keep the lower-right corner clear for the duration badge.',
            ],
            'avoid' => [
                'A sentence. A thumbnail is read in less than a second.',
                'Low contrast. The thumbnail competes against eleven others on the same screen.',
            ],
        ],

        'snapchat' => [
            'start_here' => 'snapchat_story',
            'why' => 'Snapchat is full-screen vertical only.',
            'cropping' => 'The image fills the screen and the UI is drawn over it.',
            'ui_safe_zones' => [
                ['size' => 'snapchat_story', 'top_px' => 150, 'bottom_px' => 330, 'left_px' => 60, 'right_px' => 60, 'covers' => 'The account row on top and the reply and action bar along the bottom.'],
            ],
            'file' => [
                'formats' => 'JPEG and PNG.',
                'max_file_size' => '5 MB.',
                'upload_width' => '1080px wide.',
                'color_profile' => 'sRGB.',
            ],
            'rules' => [
                'One message, held for a few seconds. Nothing that needs re-reading.',
                'High contrast. Snapchat is read one-handed, in motion.',
            ],
            'avoid' => [
                'Small type anywhere.',
            ],
        ],

        'whatsapp' => [
            'start_here' => 'whatsapp_status',
            'why' => 'A Status is full-screen vertical, the same shape as a Story.',
            'cropping' => 'Status fills the screen with the account row on top and the reply bar on the bottom.',
            'ui_safe_zones' => [
                ['size' => 'whatsapp_status', 'top_px' => 200, 'bottom_px' => 300, 'left_px' => 60, 'right_px' => 60, 'covers' => 'Account row and progress bars on top, reply field on the bottom.'],
            ],
            'file' => [
                'formats' => 'JPEG and PNG.',
                'max_file_size' => '16 MB for a shared image. A link preview image must be far smaller — see the rules.',
                'upload_width' => '1080px wide.',
                'color_profile' => 'sRGB.',
            ],
            'rules' => [
                'WhatsApp gives up on a slow link preview. Keep the `og:image` for a link you expect to be shared in chat under about 300 KB, or the preview arrives with no image.',
                'A shared image is recompressed. Send it as a document when the detail matters.',
            ],
            'avoid' => [
                'A heavy PNG as the `og:image` on a page that gets shared in chat.',
            ],
        ],

        'web' => [
            'start_here' => 'open_graph',
            'why' => '1200x630 is the one card that Facebook, LinkedIn, Slack, Discord, iMessage and WhatsApp all render, and that X crops cleanly from.',
            'cropping' => 'Every client crops this image differently. Facebook and LinkedIn show the full 1.91:1. X centre-crops to 2:1. Some chat clients crop to a square thumbnail. Only the centre of the image is guaranteed everywhere.',
            'ui_safe_zones' => [
                ['size' => 'open_graph', 'top_px' => 15, 'bottom_px' => 15, 'left_px' => 285, 'right_px' => 285, 'covers' => 'Not an overlay — the square-crop clients keep only the centre 630x630. Keep the headline and the logo inside that band.'],
                ['size' => 'email_header', 'top_px' => 0, 'bottom_px' => 0, 'left_px' => 20, 'right_px' => 20, 'covers' => 'Nothing overlays it, but many clients block images by default. The email must still read with the image missing.'],
            ],
            'file' => [
                'formats' => 'JPEG and PNG. WebP is read by most crawlers now, but JPEG is the one that never fails.',
                'max_file_size' => 'Facebook caps `og:image` at 8 MB and X at 5 MB. Aim for under 300 KB so WhatsApp and Slack render it.',
                'upload_width' => '1200px for a card, 1600px for a blog header, 1920px for a slide. An email header is designed at 1200x400 and rendered at 600px wide.',
                'color_profile' => 'sRGB.',
            ],
            'rules' => [
                'The meta tag must be in the server-rendered HTML head. A crawler does not run your JavaScript.',
                'Use an absolute HTTPS URL, and declare `og:image:width` and `og:image:height` so the crawler renders the card without fetching the file first.',
                'Every platform caches the card by URL. Change the URL, not only the file, when you change the image.',
                'Google Discover shows a large preview only when the image is at least 1200px wide AND the page sets `max-image-preview:large`.',
                'An email header is designed at twice its rendered size, constrained in the HTML, and kept under about 100 KB.',
            ],
            'avoid' => [
                'Text near the left or right edge of a card — the square-crop clients cut it.',
                'A card under 600x315. It downgrades to a small thumbnail beside the text.',
                'An email whose only message lives inside the image. Images are blocked by default in many clients.',
            ],
        ],

        'ads' => [
            'start_here' => 'ad_medium_rectangle',
            'why' => '300x250 is the placement that exists on nearly every site, so it is the one worth designing first.',
            'cropping' => 'A display slot does not crop, it refuses. The creative either matches the slot or it does not serve.',
            'ui_safe_zones' => [],
            'file' => [
                'formats' => 'JPEG and PNG.',
                'max_file_size' => 'Keep display creative under 150 KB. Ad networks enforce a weight budget and a heavy creative is dropped.',
                'upload_width' => 'Design at the exact slot size. The preset dimensions already are the slot.',
                'color_profile' => 'sRGB.',
            ],
            'rules' => [
                'One offer, one call to action, one logo. A 300x250 has room for nothing else.',
                'The logo has to be legible at the real size. A wordmark in a 160x600 skyscraper usually is not.',
                'Build the whole set from one template and change only the canvas size, so the campaign reads as one campaign.',
            ],
            'avoid' => [
                'Imitating a system dialog, a close button or a form field. Networks reject the creative.',
                'Body copy. Nobody reads a paragraph in a banner.',
            ],
        ],

        'print' => [
            'start_here' => 'print_a4_portrait',
            'why' => 'A4 at 300 dpi is the default page for a flyer or a one-sheet.',
            'cropping' => 'A printer trims the sheet. Snapsbrew renders the exact canvas with no bleed, so the trim can take a few millimetres off any edge.',
            'ui_safe_zones' => [
                ['size' => 'print_a4_portrait', 'top_px' => 120, 'bottom_px' => 120, 'left_px' => 120, 'right_px' => 120, 'covers' => 'Not an overlay — a trim allowance. 120px at 300 dpi is about 10mm. Keep text inside it and let any background colour run to the canvas edge.'],
            ],
            'file' => [
                'formats' => 'PNG for flat colour and type, JPEG for photographs.',
                'max_file_size' => 'No practical limit. A 300 dpi A4 file is large by design.',
                'upload_width' => 'The preset dimensions already are 300 dpi. Do not scale a smaller render up.',
                'color_profile' => 'sRGB. The printer converts to CMYK, and saturated blues and oranges lose some intensity in that conversion.',
            ],
            'rules' => [
                'Keep all text at least 10mm (about 120px) from every edge.',
                'Let a background colour or image run all the way to the canvas edge so a trim never exposes white paper.',
                'Type that is comfortable on a screen is large on paper. Body copy at 300 dpi is around 30 to 42px.',
            ],
            'avoid' => [
                'A design that depends on an exact edge position. The trim moves.',
                'Expecting a screen-bright colour to survive the press.',
            ],
        ],
    ];

    /**
     * Every platform key, in the display order `TemplateSizes` defines.
     *
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::GUIDES);
    }

    /**
     * The platform key a caller meant, or null when nothing matches. Handles
     * the aliases people actually type ("insta", "twitter", "og").
     */
    public static function resolve(?string $platform): ?string
    {
        $needle = strtolower(trim((string) $platform));

        if ($needle === '') {
            return null;
        }

        if (isset(self::GUIDES[$needle])) {
            return $needle;
        }

        if (isset(self::ALIASES[$needle])) {
            return self::ALIASES[$needle];
        }

        // "instagram story", "x (twitter)", "youtube shorts" — take the first
        // word that names a platform or an alias.
        foreach (preg_split('/[^a-z]+/', $needle) ?: [] as $word) {
            if (isset(self::GUIDES[$word])) {
                return $word;
            }

            if (isset(self::ALIASES[$word])) {
                return self::ALIASES[$word];
            }
        }

        return null;
    }

    /**
     * A one-line summary per platform. This is what the tool returns when the
     * caller names no platform — enough to choose one, small enough to read.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function index(): array
    {
        return array_map(function (string $key) {
            $guide = self::GUIDES[$key];
            $start = TemplateSizes::describe($guide['start_here']);

            return [
                'platform' => $key,
                'name' => TemplateSizes::PLATFORMS[$key] ?? $key,
                'start_here' => $guide['start_here'],
                'dimensions' => $start ? $start['width'].'x'.$start['height'] : null,
                'why' => $guide['why'],
            ];
        }, self::keys());
    }

    /**
     * The full guidance for one platform, with its size presets resolved from
     * `TemplateSizes`.
     *
     * @return array<string, mixed>|null
     */
    public static function find(string $key): ?array
    {
        $guide = self::GUIDES[$key] ?? null;

        if ($guide === null) {
            return null;
        }

        return array_merge(
            [
                'platform' => $key,
                'name' => TemplateSizes::PLATFORMS[$key] ?? $key,
                'specs_reviewed' => self::SPECS_REVIEWED,
            ],
            $guide,
            [
                'sizes' => self::sizesFor($key),
                'next_steps' => [
                    'Call `image-purpose-guide` for what this image is FOR — a link preview, a carousel, an avatar and a thumbnail follow different rules on the same platform.',
                    'Call `design-guide` for how to compose the layers, and `describe-layers` for the exact property names.',
                    'Create the template with the `start_here` preset as `size`, then `preview-template` before the final render.',
                ],
            ]
        );
    }

    /**
     * Every platform's full guidance.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function catalog(): array
    {
        return array_values(array_filter(array_map(
            fn (string $key) => self::find($key),
            self::keys()
        )));
    }

    /**
     * This platform's presets, straight out of the one size catalog.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function sizesFor(string $platform): array
    {
        return TemplateSizes::forPlatform($platform);
    }
}
