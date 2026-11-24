<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\ApplyEditorOperationsTool;
use App\Mcp\Tools\CancelSnapsBatchTool;
use App\Mcp\Tools\CreateSnapFromDesignTool;
use App\Mcp\Tools\CreateSnapsBatchTool;
use App\Mcp\Tools\CreateSnapTool;
use App\Mcp\Tools\CreateTemplateTool;
use App\Mcp\Tools\DescribeEditorDocumentTool;
use App\Mcp\Tools\DescribeLayersTool;
use App\Mcp\Tools\DesignGuideTool;
use App\Mcp\Tools\EditTemplateTool;
use App\Mcp\Tools\ExportCreativePackageTool;
use App\Mcp\Tools\GetCreativePackageTool;
use App\Mcp\Tools\GetEditorDocumentTool;
use App\Mcp\Tools\GetSnapsBatchTool;
use App\Mcp\Tools\GetTemplateDetailsTool;
use App\Mcp\Tools\ImagePurposeGuideTool;
use App\Mcp\Tools\ListTemplateSizesTool;
use App\Mcp\Tools\ListTemplatesTool;
use App\Mcp\Tools\ListVariantPropertiesTool;
use App\Mcp\Tools\PlatformGuideTool;
use App\Mcp\Tools\PreflightEditorDocumentTool;
use App\Mcp\Tools\PreviewDesignTool;
use App\Mcp\Tools\PreviewSnapTool;
use App\Mcp\Tools\PreviewTemplateTool;
use App\Mcp\Tools\PublishTemplateTool;
use App\Mcp\Tools\PutEditorDocumentTool;
use App\Mcp\Tools\WhoAmITool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Snapsbrew')]
#[Version('1.0.0')]
#[Instructions(self::WORKFLOW)]
class SnapsbrewServer extends Server
{
    /**
     * The one definition of the agent workflow.
     *
     * The #[Instructions] attribute above and the `skill.md` route both render
     * this constant. Two hand-typed copies of a workflow drift by the second
     * edit, so there is one string and two readers.
     */
    public const WORKFLOW = <<<'MARKDOWN'
Snapsbrew renders branded images ("snaps") from reusable templates. Templates
have named layers; you customize a snap by passing `variants` that override
specific layers.

Recommended workflow — ALWAYS preview before the final render:

1. `whoami` — confirm that the token works.
2. `list-templates` — find a template; note its `id` (a UUID).
3. `get-template-details` — read the template's layer keys and their current
   values. You MUST use these layer keys when building `variants`.
4. `preview-template` or `preview-snap` — renders nothing final. Returns
   WATERMARKED HTML (render it to show the human the result). Iterate here until
   it looks right. The response echoes back the exact `variants` you used.
5. `create-snap` — renders the final image. Only call this after the human
   approves a preview. Pass the SAME `variants` you previewed to get the clean, final,
   un-watermarked image.

`variants` format: an array of objects, each `{ "layer": "<key>", ... }` with
exactly one override per object:
  - `"text": "..."`            replace a text layer's content
  - `"image_url": "https://..."` replace an image layer (must be a public URL)
  - `"background_color": "#hex"` replace a background color
  - `"qr_content": "..."`       set a QR code's content
  - `"barcode_content": "..."`  set a barcode's value (valid for its symbology)
  - `"hidden": true`            drop the layer from the render

Call `list-variant-properties` for the full catalog with value types and
examples, and `get-template-details` for a specific template's layer keys.

Templates can also be created (`create-template`) and edited
(`edit-template`, by any account in your company). Set the canvas with a
`size` preset — `list-template-sizes` returns every preset with its
dimensions, aspect ratio, and safe margin, so `"size": "instagram_story"`
replaces guessing `width`/`height`. Pass explicit `width`/`height` only for a
size no preset covers.

Editing mutates a saved asset that existing snaps depend on — only
do it when explicitly asked. Before building a `layers` definition for either,
call `describe-layers` for the layer object shape, the full property vocabulary,
and a worked example — passing malformed layers (a layer with no `key` or a
property with no `name`) is rejected with a message saying exactly what to fix.
For HOW to compose those layers into a good-looking design — canvas presets,
layer-order convention, typography/color rules, and composition recipes — call
`design-guide` first. `describe-layers` tells you the vocabulary;
`design-guide` tells you how to use it well.

For direct v2 layout edits, call `describe-editor-document`, then
`get-editor-document`, then `apply-editor-operations` with that unchanged
revision. Its only grammars are `set_layer_property` and `set_layer_type`;
on `editor_document_conflict`, reread instead of overwriting the draft. Before
publishing a v2 draft, call `preflight-editor-document`; it checks known render
invariants but cannot guarantee visual quality.

Designing for a real destination — start here, before `create-template`. Three
guidance tools answer three different questions, and a good snap usually
needs two of them:
  - `platform-guide` — WHERE it is published. Call it the moment the human
    names a platform ("an image for Instagram", "a LinkedIn post", "an X
    card", "a TikTok"). It returns the size preset to start from, how that
    platform crops the image, the directional UI safe zones where the platform
    paints its own controls over a Story / Reel / TikTok / banner, the file
    limits, and platform-specific rules. It accepts "insta", "twitter", "og".
  - `image-purpose-guide` — WHAT the image is for. Call it when the human
    describes the job instead of the place: an og:image / meta tag image, a
    carousel or gallery, a feed photo, a profile picture, a cover or banner, a
    video thumbnail, an ad, a product shot, an email header, a blog hero, a
    slide. It returns the rules and failure modes for that job, a layer recipe,
    and a preflight checklist. `link_preview` also returns the exact og: and
    twitter: meta tags for the page head.
  - `design-guide` — HOW to compose it, on any canvas.
A platform and a purpose intersect: an Instagram carousel needs both, and the
purpose rules (every slide one aspect ratio) and the platform rules (the safe
zone, the crop) both apply.

No template yet? Design inline. `preview-design` renders a canvas
(a `size` preset, or `width` and `height`) plus a `layers` definition in the
same shape `create-template` takes, and echoes back the exact canvas and layers
it rendered. `create-snap-from-design` renders the final image from
that same canvas and those same layers. Nothing is saved: the snap has no
template, and `template_id` is null in its response. Pick the path by reuse:
if the human will use this design again, call `create-template` first so the
design has a saved template; if it is a one-off, design inline.

Many snaps at once: `create-snaps-batch` renders up to 100 items from ONE
template in one call, with a different set of variants per item. It returns a
batch id right away and renders the items in the background. Read progress
with `get-snaps-batch` and stop polling when `status` is `completed`, `failed` or `canceled`.
`completed` means the batch ran to the end and at least one item rendered, so
read the `failed` count as well as the status. Preview with `preview-snap` before
you start a batch. `cancel-snaps-batch` stops one that is still running. Items
that already rendered stay, items that had not started never render, and a
canceled batch cannot be resumed.

Draft vs published: `edit-template` writes the template's DRAFT. New snaps and
the preview tools render the most recently PUBLISHED version — so an edit does
NOT take effect until you publish it. After editing, call `publish-template`
(any account in your company) to make the draft live; `get-template-details`
reports `has_unpublished_edits: true` whenever a draft is ahead of the
published version.

Creative packages: `export-creative-package` renders up to 10 FINAL images
from published template versions into one portable package. It returns a
package id right away and renders in the background. `idempotency_key` is
required, and it makes a retry safe. A
second export with the same key and the same items returns the same package,
with `replayed: true`, and starts no second one. The same key with different
items answers `creative_package_idempotency_conflict`. A key replays its
package for 90 days.

Every package response carries `status` and `next_action`. Read the pair and
do what the row says:

    status   | next_action      | what you do
    building | poll             | Wait `poll_after_seconds`. Then call `get-creative-package` again.
    ready    | download         | Read `file_url` on every manifest item and save each file.
    partial  | retry_incomplete | Save the ready files. Then follow the retry rule for the failed items.
    failed   | inspect_failures | Read `failure` on every item. Then follow the retry rule.

Retry rule: read `failure.code` and `failure.retryable` on each failed item.
If `retryable` is true, clear the cause first:
`creative_package_entitlement_required` needs an account that is a customer
or admin member of the company of the package. Then export again with the same key. The replay reopens each retryable item
whose cause is clear. It never renders a ready item again. If `retryable` is
false, fix the template version and export again with a new key.

`get-creative-package` renders nothing. Every
read signs fresh file URLs and returns a new `url_expires_at`. When a URL
expires, read the package again. Do not export again to refresh a URL.

Every package item names a template version, and `publish-template` creates
one. Publishing freezes the current draft as an immutable version number. It
posts nothing anywhere. Read the number with `get-template-details` and pass
it as `template_version`.

Errors: every tool that fails returns one JSON object.

    {
      "error": {
        "code": "template_not_found",
        "message": "No template with id `a1b2` exists in your account.",
        "fix": "Call `list-templates` to read the template ids in your account. Then call this tool again with an id from that list.",
        "retryable": false,
        "details": { "template_id": "a1b2" }
      }
    }

Read `fix` and make the call it names. Branch on `code`, which is stable, and
never on `message`, which can be reworded. Send the same call again only when
`retryable` is true. Every other error needs a different input, or a person.
`details` carries the values you sent, so that you can build the next call.
MARKDOWN;

    /**
     * `tools/list` answers in one page.
     *
     * The package default is 15 and this server registers 27 tools, so a first
     * call returned every tool except `create-template`, `edit-template` and
     * `publish-template`. A client that follows `nextCursor` finds them, which
     * the specification asks for, and a client that does not silently loses the
     * three tools that author templates. One page removes the question. Raise
     * this before the tool count reaches it. `maxPaginationLength` is 50.
     */
    public int $defaultPaginationLength = 30;

    protected array $tools = [
        WhoAmITool::class,
        ListTemplatesTool::class,
        GetTemplateDetailsTool::class,
        GetEditorDocumentTool::class,
        DescribeEditorDocumentTool::class,
        ListVariantPropertiesTool::class,
        PreviewTemplateTool::class,
        PreviewSnapTool::class,
        PreviewDesignTool::class,
        CreateSnapTool::class,
        CreateSnapFromDesignTool::class,
        CreateSnapsBatchTool::class,
        GetSnapsBatchTool::class,
        CancelSnapsBatchTool::class,
        DescribeLayersTool::class,
        DesignGuideTool::class,
        PlatformGuideTool::class,
        ImagePurposeGuideTool::class,
        ListTemplateSizesTool::class,
        CreateTemplateTool::class,
        EditTemplateTool::class,
        PublishTemplateTool::class,
        PutEditorDocumentTool::class,
        ApplyEditorOperationsTool::class,
        PreflightEditorDocumentTool::class,
        ExportCreativePackageTool::class,
        GetCreativePackageTool::class,
    ];

    protected array $resources = [];

    protected array $prompts = [];
}
