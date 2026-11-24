<?php

namespace App\Support;

/**
 * Single source of truth for PER-PURPOSE image guidance — what the image is
 * FOR, and the rules that follow from the job rather than from the platform.
 *
 * The three guidance catalogs answer three different questions:
 *   - `App\Support\DesignGuide`       — how to compose layers well (any canvas).
 *   - `App\Support\PlatformGuide`     — what a platform does to the result after upload.
 *   - `App\Support\ImagePurposeGuide` — what the image has to achieve (this class).
 *
 * A link preview, a carousel slide and an avatar are three different jobs with
 * three different failure modes, and the platform is a secondary question for
 * all of them. An agent that hears "I need an og:image" should land here.
 *
 * Like `PlatformGuide`, this class stores preset NAMES, never pixel numbers —
 * `App\Support\TemplateSizes` resolves them. `ImagePurposeGuideTest` asserts
 * every named preset still exists.
 */
class ImagePurposeGuide
{
    /**
     * What a caller is likely to type, mapped to a purpose key. Matched
     * case-insensitively as a whole phrase first, then word by word.
     *
     * @var array<string, string>
     */
    public const ALIASES = [
        'og' => 'link_preview',
        'og image' => 'link_preview',
        'ogimage' => 'link_preview',
        'open graph' => 'link_preview',
        'opengraph' => 'link_preview',
        'meta' => 'link_preview',
        'meta tag' => 'link_preview',
        'meta image' => 'link_preview',
        'share card' => 'link_preview',
        'social card' => 'link_preview',
        'preview' => 'link_preview',
        'unfurl' => 'link_preview',
        'twitter card' => 'link_preview',
        'photo' => 'feed_post',
        'post' => 'feed_post',
        'feed' => 'feed_post',
        'graphic' => 'feed_post',
        'gallery' => 'carousel',
        'slides' => 'carousel',
        'swipe' => 'carousel',
        'album' => 'carousel',
        'multi image' => 'carousel',
        'reel' => 'story',
        'reels' => 'story',
        'short' => 'story',
        'shorts' => 'story',
        'status' => 'story',
        'vertical' => 'story',
        'avatar' => 'profile_picture',
        'pfp' => 'profile_picture',
        'profile' => 'profile_picture',
        'logo' => 'profile_picture',
        'banner' => 'cover',
        'header' => 'cover',
        'cover photo' => 'cover',
        'thumbnail' => 'video_thumbnail',
        'thumb' => 'video_thumbnail',
        'youtube thumbnail' => 'video_thumbnail',
        'advert' => 'ad',
        'ads' => 'ad',
        'advertisement' => 'ad',
        'creative' => 'ad',
        'product' => 'product_shot',
        'product photo' => 'product_shot',
        'listing' => 'product_shot',
        'ecommerce' => 'product_shot',
        'catalog' => 'product_shot',
        'email' => 'email_header',
        'newsletter' => 'email_header',
        'email banner' => 'email_header',
        'blog' => 'article_header',
        'article' => 'article_header',
        'featured image' => 'article_header',
        'hero' => 'article_header',
        'slide' => 'presentation_slide',
        'deck' => 'presentation_slide',
        'presentation' => 'presentation_slide',
    ];

    /**
     * One entry per purpose. `sizes` holds preset values only.
     *
     * @var array<string, array<string, mixed>>
     */
    private const PURPOSES = [
        'link_preview' => [
            'name' => 'Link preview / meta tag image',
            'what_it_is' => 'The card a URL turns into when it is pasted anywhere: Facebook, LinkedIn, X, Slack, Discord, iMessage, WhatsApp. One image serves them all, read from the page `og:image` meta tag.',
            'use_when' => 'You are shipping a page, a blog post, a product or a release and you want the shared link to look deliberate instead of blank.',
            'sizes' => ['open_graph', 'twitter_card', 'linkedin_feed', 'facebook_feed'],
            'rules' => [
                'Build ONE image at 1200x630 (1.91:1). Every major client renders it, and X crops it to 2:1 without losing the middle.',
                'Keep the headline, the logo and any face inside the centre 630x630. Some chat clients crop the card to a square and only that band always survives.',
                'The card renders about 500px wide in a phone feed. Nothing under 28px on a 1200px canvas is readable there.',
                'Say what the page IS. The card is read before the title, not after it.',
                'Serve it from an absolute HTTPS URL that needs no cookie, in the server-rendered HTML head. A crawler does not run your JavaScript.',
                'Declare `og:image:width` and `og:image:height` so the crawler can render the card without fetching the file first.',
                'Change the image URL when you change the image. Every platform caches the card by URL.',
                'Keep it under 300 KB if the link gets shared in WhatsApp or Slack; under 5 MB always; under 8 MB for Facebook.',
            ],
            'meta_tags' => [
                'note' => 'Put these in the server-rendered `<head>`. `twitter:card` is what upgrades X from the small square card to the large one.',
                'html' => '<meta property="og:title" content="…">'."\n"
                    .'<meta property="og:description" content="…">'."\n"
                    .'<meta property="og:image" content="https://example.com/snap.png">'."\n"
                    .'<meta property="og:image:width" content="1200">'."\n"
                    .'<meta property="og:image:height" content="630">'."\n"
                    .'<meta property="og:url" content="https://example.com/page">'."\n"
                    .'<meta name="twitter:card" content="summary_large_image">'."\n"
                    .'<meta name="twitter:image" content="https://example.com/snap.png">',
            ],
            'layer_recipe' => 'base fill (brand colour or a two-stop gradient) → optional photo with a dark scrim over it → headline, autoResize, 64–96px, inside the centre band → one supporting line at 32–40px → logo in a bottom corner, inside the safe margin → one accent bar or pill.',
            'avoid' => [
                'Text near the left or right edge — the square-crop clients cut it.',
                'A card under 600x315. It downgrades to a small thumbnail beside the text.',
                'A generic stock photo with no words. The card exists to say something.',
                'Injecting the meta tag with JavaScript.',
            ],
            'checklist' => [
                'Canvas is 1200x630.',
                'Headline and logo sit inside the centre 630x630.',
                'Smallest text is at least 28px.',
                'Meta tags are in the server-rendered head, with width and height declared.',
                'The file URL is new if the image changed.',
            ],
            'also_read' => ['web', 'facebook', 'x', 'linkedin', 'whatsapp'],
        ],

        'feed_post' => [
            'name' => 'Feed post (single photo or graphic)',
            'what_it_is' => 'One image posted into a scrolling feed. It competes with everything else on the screen and it gets about one second to earn a stop.',
            'use_when' => 'An announcement, a quote, a stat, a promo, a single product — anything that fits in one frame.',
            'sizes' => ['instagram_post', 'instagram_square_post', 'linkedin_portrait', 'facebook_portrait', 'threads_post'],
            'rules' => [
                'Default to 4:5 portrait (1080x1350). It takes the most mobile feed area on Instagram, Facebook, LinkedIn and Threads at once.',
                'One idea. If it needs a second idea, it needs a carousel.',
                'The largest element is the message. Everything else is support.',
                'Text has to read at thumbnail size: roughly 40px minimum on a 1080px canvas.',
                'Keep every text, logo and button inside the safe margin the preset reports — feeds crop the edges differently on each platform.',
                'Put the detail in the caption. The image carries the hook.',
            ],
            'layer_recipe' => 'base fill → optional photo with a scrim → eyebrow (small, uppercase, 28–32px) → headline (autoResize, 72–110px) → one supporting line → logo in a fixed corner.',
            'avoid' => [
                'A paragraph rendered as an image.',
                'A ratio taller than 4:5 in a feed — the extra height is cropped away.',
                'Low contrast text over a busy photo with no scrim.',
            ],
            'checklist' => [
                'Canvas is 4:5 unless a platform rule says otherwise.',
                'One headline, readable at thumbnail size.',
                'Everything inside the safe margin.',
                'At most two fonts and four colours.',
            ],
            'also_read' => ['instagram', 'linkedin', 'facebook', 'threads'],
        ],

        'carousel' => [
            'name' => 'Carousel / gallery',
            'what_it_is' => 'A set of images in one post that the reader swipes through. The first slide sells the swipe; the rest deliver.',
            'use_when' => 'A list, a step-by-step, a before and after, a set of products, or any idea too big for one frame.',
            'sizes' => ['instagram_post', 'instagram_square_post', 'linkedin_square', 'tiktok_vertical'],
            'rules' => [
                'Every slide must share ONE aspect ratio. A slide with a different ratio is cropped to match the first one.',
                'Slide 1 is a hook, not a title. It has to open a gap the reader wants closed.',
                'One idea per slide. Five to eight slides is where completion stays high.',
                'The last slide carries the ask: save, follow, or the link.',
                'Keep the background, the type scale and the logo position identical on every slide so the swipe reads as one object.',
                'In Snapsbrew: build ONE template, then render one snap per slide with `variants` overriding the text layer. Do not build eight templates.',
                'Number the slides only if the order carries meaning. A counter costs a layer and buys little.',
            ],
            'layer_recipe' => 'One template: base fill → optional pattern at low opacity → slide-number text (small, fixed corner) → headline text layer with autoResize:true (this is the layer each slide overrides) → body text layer (also overridden) → logo, same corner on every slide.',
            'avoid' => [
                'Mixing a square slide into a portrait set.',
                'A title card as slide 1 — it tells the reader they can stop.',
                'Moving the logo or the type size between slides.',
                'Twenty slides. Nobody finishes them.',
            ],
            'checklist' => [
                'Every slide is the same canvas size.',
                'Slide 1 is a hook.',
                'One idea per slide, 5 to 8 slides.',
                'Last slide has the ask.',
                'All slides rendered from one template with text variants.',
            ],
            'also_read' => ['instagram', 'linkedin', 'tiktok', 'threads'],
        ],

        'story' => [
            'name' => 'Story / Reel / vertical full-screen frame',
            'what_it_is' => 'A full-screen 9:16 frame with the platform UI painted over the top and the bottom of it. The usable area is the middle band, not the canvas.',
            'use_when' => 'An ephemeral update, a link sticker, a countdown, a poll background, a Reel or Shorts cover.',
            'sizes' => ['instagram_story', 'instagram_reel', 'facebook_story', 'tiktok_vertical', 'youtube_shorts', 'snapchat_story', 'whatsapp_status'],
            'rules' => [
                'Design inside the UI safe zone, not inside the canvas. Call `platform-guide` for the exact insets — they differ by platform and TikTok is the most aggressive.',
                'As a portable default on 1080x1920: keep everything inside the centre band, roughly 250px clear on top and 420px clear on the bottom.',
                'One message per frame. A Story is held for a couple of seconds.',
                'Let a background colour or image run to every edge. Only the CONTENT respects the safe zone.',
                'Leave a clear area where the link or poll sticker will sit if you plan to add one.',
            ],
            'layer_recipe' => 'full-bleed base fill or photo (edge to edge, zIndex 0) → scrim if text sits on a photo → headline inside the middle band (72–110px) → one supporting line → logo just above the bottom safe zone, never in it.',
            'avoid' => [
                'Text in the bottom third. The caption, the audio ticker and the reply bar all live there.',
                'A logo in the bottom-right. The share icon sits on it.',
                'Centring the subject on the canvas centre instead of the centre of the usable band.',
            ],
            'checklist' => [
                'Canvas is 1080x1920.',
                'All content inside the platform UI safe zone.',
                'Background runs to every edge.',
                'One message, large type.',
            ],
            'also_read' => ['instagram', 'tiktok', 'snapchat', 'whatsapp', 'youtube', 'facebook'],
        ],

        'profile_picture' => [
            'name' => 'Profile picture / avatar',
            'what_it_is' => 'A small square that every platform masks into a circle and renders between 32px and 110px.',
            'use_when' => 'An account avatar, a page logo, a contributor headshot.',
            'sizes' => ['instagram_profile'],
            'rules' => [
                'It is masked to a circle. Keep everything inside the inscribed circle — roughly 15 percent in from every edge of the square.',
                'It has to work at 32px. Design it, then look at it small before you ship it.',
                'A full wordmark almost never survives. Use a monogram, a symbol, or a single letter.',
                'Solid, high-contrast background. A photographic background turns to mush at 32px.',
                'Same avatar everywhere. Recognition is the entire job.',
            ],
            'layer_recipe' => 'solid base fill (brand colour) → one centred mark or one or two letters at a large size → nothing else.',
            'avoid' => [
                'Text near the square corners — the circle mask removes them.',
                'A tagline. There is no room.',
                'A busy photo, a gradient with low contrast, or a thin outlined logo.',
            ],
            'checklist' => [
                'Square canvas.',
                'Everything inside the inscribed circle.',
                'Legible at 32px.',
                'One mark, solid background.',
            ],
            'also_read' => ['instagram', 'x', 'linkedin', 'youtube'],
        ],

        'cover' => [
            'name' => 'Cover / banner / header',
            'what_it_is' => 'The wide strip at the top of a profile or a page. Every platform crops it differently by device, and most of them park the profile photo on top of one corner.',
            'use_when' => 'A page cover, a profile banner, an event header, a channel art.',
            'sizes' => ['facebook_cover', 'x_header', 'linkedin_banner', 'youtube_banner', 'facebook_event_cover'],
            'rules' => [
                'Only the horizontal centre is guaranteed. Put the message there and treat the sides as decoration.',
                'The profile photo overlaps a corner — lower-left on LinkedIn and X desktop, lower-left on a Facebook page. Keep that corner empty.',
                'A YouTube channel banner is the extreme case: only the centre 1235x338 of 2560x1440 is guaranteed on a TV.',
                'Short line, large type. A banner is skimmed, never read.',
                'Test the crop on a phone. Mobile is where the banner loses the most.',
            ],
            'layer_recipe' => 'full-bleed base fill or photo → optional scrim → one short line centred horizontally → logo or mark beside it, inside the guaranteed centre.',
            'avoid' => [
                'A sentence that spans the full width. Mobile cuts both ends.',
                'Anything in the corner the profile photo covers.',
                'Small type. A banner renders short on a phone.',
            ],
            'checklist' => [
                'Message inside the guaranteed centre band.',
                'Profile-photo corner is empty.',
                'Checked at mobile crop.',
            ],
            'also_read' => ['facebook', 'x', 'linkedin', 'youtube'],
        ],

        'video_thumbnail' => [
            'name' => 'Video thumbnail',
            'what_it_is' => 'The still that decides whether the video is opened. It renders at about 210x118 on a phone, next to eleven competitors.',
            'use_when' => 'A YouTube video, a course lesson, a webinar replay, a podcast episode.',
            'sizes' => ['youtube_thumbnail'],
            'rules' => [
                'Four words maximum. The title is already printed beside the thumbnail — do not repeat it, extend it.',
                'One face or one object, large. A face with a clear expression outperforms a scene.',
                'High contrast and a saturated accent. It has to win against a wall of other thumbnails.',
                'Keep the lower-right corner clear for the duration badge.',
                'Test it at 210px wide. If the words are unreadable there, the design is wrong at every size.',
                'Keep the file under 2 MB — YouTube enforces this one.',
            ],
            'layer_recipe' => 'photo or bold base fill → subject on one side → two to four huge words on the other (autoResize, 110–160px) → one accent shape behind the words for contrast → small logo in a top corner.',
            'avoid' => [
                'A sentence.',
                'Repeating the video title verbatim.',
                'Anything important in the lower-right corner.',
                'Low contrast, thin type, or three competing focal points.',
            ],
            'checklist' => [
                'Canvas is 1280x720.',
                'Four words or fewer.',
                'Lower-right corner is clear.',
                'Readable at 210px wide.',
                'Under 2 MB.',
            ],
            'also_read' => ['youtube'],
        ],

        'ad' => [
            'name' => 'Ad creative',
            'what_it_is' => 'A paid placement. It is interrupting someone, so it has to be legible and honest in one glance, and it has to match the slot exactly.',
            'use_when' => 'A display banner, a paid social placement, a retargeting set.',
            'sizes' => ['ad_medium_rectangle', 'ad_leaderboard', 'ad_billboard', 'ad_half_page', 'ad_skyscraper', 'instagram_post', 'facebook_feed'],
            'rules' => [
                'One offer, one call to action, one logo. Nothing else fits and nothing else helps.',
                'The brand has to be visible without being the headline. The offer leads.',
                'Build the whole set from one template and change only the canvas size, so the campaign reads as one campaign.',
                'Check the logo at the real placement size. A wordmark in a 160x600 skyscraper usually is not legible.',
                'Keep display creative under 150 KB. Networks enforce a weight budget.',
                'Match the slot exactly. A display slot does not crop, it refuses.',
            ],
            'layer_recipe' => 'base fill → optional product image → offer headline (largest element) → one qualifier line → CTA pill in the accent colour → logo, small, fixed corner.',
            'avoid' => [
                'Imitating a system dialog, a close button or a form field. Networks reject the creative.',
                'Body copy in a banner.',
                'A claim the landing page does not repeat.',
            ],
            'checklist' => [
                'Canvas matches the slot exactly.',
                'One offer, one CTA, one logo.',
                'Logo legible at real size.',
                'Landing page repeats the claim.',
            ],
            'also_read' => ['ads', 'instagram', 'facebook', 'linkedin'],
        ],

        'product_shot' => [
            'name' => 'Product / listing image',
            'what_it_is' => 'The image in a shop or a marketplace listing. Marketplaces enforce hard rules on the FIRST image and relax them for the rest.',
            'use_when' => 'A store listing, a catalogue, a marketplace upload, a shopping feed.',
            'sizes' => ['instagram_square_post', 'pinterest_pin', 'facebook_card'],
            'rules' => [
                'The main image is square (1:1) on a pure white background, with the product filling about 85 percent of the frame.',
                'No text, logo, watermark, border or prop on the main image. Marketplaces reject it.',
                'At least 1000px on the longest side so zoom works; 1600 to 2000px is the practical target.',
                'Put every text overlay, infographic, size chart and lifestyle scene on the SECONDARY images, where they are allowed and where they sell.',
                'Same crop, same margin and same lighting across the whole catalogue. Inconsistency reads as amateur at grid size.',
                'The product must be recognisable as a 200px thumbnail in a search grid.',
            ],
            'layer_recipe' => 'Main image: white base fill → product image centred, filling ~85 percent → nothing else. Secondary image: light base fill → product → one benefit line → one callout → logo.',
            'avoid' => [
                'A logo or a price badge on the main image.',
                'A lifestyle background on the main image.',
                'Different margins from listing to listing.',
            ],
            'checklist' => [
                'Main image is square, white, product at ~85 percent, no text.',
                'At least 1000px on the long side.',
                'Text and context live on secondary images.',
                'Recognisable at 200px.',
            ],
            'also_read' => ['instagram', 'pinterest', 'facebook'],
        ],

        'email_header' => [
            'name' => 'Email header / banner',
            'what_it_is' => 'The image at the top of an HTML email. Many clients block it by default and several invert its colours in dark mode.',
            'use_when' => 'A newsletter masthead, a campaign hero, an announcement bar.',
            'sizes' => ['email_header'],
            'rules' => [
                'The template is 600px wide. Design the image at 2x (1200x400) and constrain it to 600px in the HTML so it stays sharp on retina.',
                'The email must still read with the image missing. Never put the only copy of the message inside the image.',
                'Keep the header under about 100 KB. The whole email under 1 MB.',
                'Write real alt text on the `img` tag. With images off, that alt text IS the header.',
                'Dark mode inverts backgrounds in some clients. A white logo on a transparent PNG disappears — use an opaque background instead.',
                'Do not put a clickable-looking button inside the image. Build the button in HTML so it works with images off.',
            ],
            'layer_recipe' => 'solid base fill (opaque, never transparent) → logo or wordmark → one short line at 40–56px on the 1200px canvas → optional accent rule.',
            'avoid' => [
                'A transparent PNG logo.',
                'A CTA button rendered into the image.',
                'A 600px-wide export on a retina screen. It looks soft.',
            ],
            'checklist' => [
                'Designed at 1200x400, rendered at 600px.',
                'Opaque background.',
                'Under 100 KB.',
                'Alt text written, and the email reads without the image.',
            ],
            'also_read' => ['web'],
        ],

        'article_header' => [
            'name' => 'Article / blog header image',
            'what_it_is' => 'The hero at the top of a post. It is usually reused as the `og:image` and as the thumbnail in search, Discover and related-post cards.',
            'use_when' => 'A blog post, a documentation page, a release note, a case study.',
            'sizes' => ['blog_header', 'open_graph'],
            'rules' => [
                'Design at 16:9 and at least 1200px wide. Google Discover shows a large preview only above 1200px, and only when the page sets `max-image-preview:large`.',
                'Keep every word in the centre. Cards crop this image to squares and to 1.91:1 in different places.',
                'If it doubles as the `og:image`, it has to survive the 1200x630 crop. Check both.',
                'Avoid a generic stock photo. Discover explicitly deprioritises them.',
                'Reference the same image in the page structured data and in `og:image` so search and social agree on the thumbnail.',
            ],
            'layer_recipe' => 'base fill or photo with a scrim → title text in the centre band (72–96px) → small category eyebrow → logo in a corner.',
            'avoid' => [
                'Text at the edges. Every downstream crop takes them.',
                'An image under 1200px wide.',
                'A different image in the structured data than in `og:image`.',
            ],
            'checklist' => [
                'At least 1200px wide, 16:9.',
                'Text inside the centre band.',
                'Survives the 1200x630 crop if it doubles as the card.',
                'Same URL in structured data and `og:image`.',
            ],
            'also_read' => ['web'],
        ],

        'presentation_slide' => [
            'name' => 'Presentation slide',
            'what_it_is' => 'A 16:9 frame read from a distance, or scrolled past as an image in a feed.',
            'use_when' => 'A deck, a LinkedIn document post, a webinar frame, a screenshot-friendly summary.',
            'sizes' => ['presentation_slide'],
            'rules' => [
                'One idea per slide. A slide with two ideas is two slides.',
                'Body text at 28px minimum on a 1920px canvas. A slide is read from across a room or at half size in a feed.',
                'Keep a wide, consistent margin. Edge-to-edge text reads as cramped on a projector.',
                'Same type scale and the same logo position on every slide in the set.',
                'Build one template and override the text per slide, the same way a carousel works.',
            ],
            'layer_recipe' => 'base fill → slide title (56–72px) → body or one big number (autoResize) → footer line with the deck name and the slide number → logo, same corner throughout.',
            'avoid' => [
                'Bullet lists of full sentences.',
                'A chart with a legend that needs squinting.',
                'Changing the layout between slides.',
            ],
            'checklist' => [
                'Canvas is 1920x1080.',
                'One idea, body text at least 28px.',
                'Consistent margin, type scale and logo position.',
            ],
            'also_read' => ['web', 'linkedin'],
        ],
    ];

    /**
     * Every purpose key, in catalog order.
     *
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::PURPOSES);
    }

    /**
     * The purpose key a caller meant, or null when nothing matches. Handles the
     * words people actually use ("og image", "gallery", "thumbnail").
     */
    public static function resolve(?string $purpose): ?string
    {
        $needle = strtolower(trim((string) $purpose));

        if ($needle === '') {
            return null;
        }

        $needle = preg_replace('/[^a-z]+/', ' ', $needle) ?? $needle;
        $needle = trim($needle);
        $underscored = str_replace(' ', '_', $needle);

        if (isset(self::PURPOSES[$underscored])) {
            return $underscored;
        }

        if (isset(self::ALIASES[$needle])) {
            return self::ALIASES[$needle];
        }

        // "instagram carousel", "a photo for the feed" — the first word that
        // names a purpose or an alias wins.
        foreach (explode(' ', $needle) as $word) {
            if (isset(self::PURPOSES[$word])) {
                return $word;
            }

            if (isset(self::ALIASES[$word])) {
                return self::ALIASES[$word];
            }
        }

        return null;
    }

    /**
     * A one-line summary per purpose — what the tool returns when the caller
     * names none.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function index(): array
    {
        return array_map(function (string $key) {
            $purpose = self::PURPOSES[$key];

            return [
                'purpose' => $key,
                'name' => $purpose['name'],
                'use_when' => $purpose['use_when'],
                'sizes' => $purpose['sizes'],
            ];
        }, self::keys());
    }

    /**
     * The full guidance for one purpose, with its size presets resolved from
     * `TemplateSizes`.
     *
     * @return array<string, mixed>|null
     */
    public static function find(string $key): ?array
    {
        $purpose = self::PURPOSES[$key] ?? null;

        if ($purpose === null) {
            return null;
        }

        return array_merge(
            ['purpose' => $key],
            $purpose,
            [
                'sizes' => self::hydrateSizes($purpose['sizes']),
                'next_steps' => [
                    'Call `platform-guide` for the platform you are publishing to — it gives the crop, the UI safe zone and the file limits that this purpose has to live inside.',
                    'Call `design-guide` for layer order, typography and colour, and `describe-layers` for the exact property names.',
                    'Create the template with one of the `sizes` presets, then `preview-template` before the final render.',
                ],
            ]
        );
    }

    /**
     * Every purpose's full guidance.
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
     * Preset values turned into full catalog entries. An unknown value is
     * dropped rather than rendered as a null — `ImagePurposeGuideTest` fails
     * before that can reach a caller.
     *
     * @param  array<int, string>  $values
     * @return array<int, array<string, mixed>>
     */
    private static function hydrateSizes(array $values): array
    {
        return array_values(array_filter(array_map(
            fn (string $value) => TemplateSizes::describe($value),
            $values
        )));
    }
}
