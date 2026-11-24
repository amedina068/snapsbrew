<?php

namespace App\Mcp\Tools;

use App\Exceptions\IdempotencyConflictException;
use App\Http\Resources\SnapResource;
use App\Mcp\Tools\Concerns\CreatesMcpSnaps;
use App\Models\Snap;
use App\Rules\SafeLayers;
use App\Rules\ValidLayers;
use App\Services\SnapService;
use App\Support\CanvasRules;
use App\Support\IdempotencyRules;
use App\Support\InlineDesign;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('create-snap-from-design')]
#[Title('Create a snap from an inline design')]
#[Description('Renders the FINAL, un-watermarked snap from a design you describe inline: a canvas (`size` preset, or `width` and `height`) plus a `layers` definition, with NO template. No template is saved. Only call this after the human has approved a preview-design result, and pass the SAME canvas and layers you previewed. Returns the snap id and the image URL; template_id is null. Idempotency keys are shared across every snap-creation tool. If the human will reuse this design, call create-template instead.')]
// destructiveHint stays true although a create is additive. A final render
// cannot be undone, and this hint makes a client ask the human first.
#[IsDestructive]
#[IsOpenWorld]
#[IsReadOnly(false)]
class CreateSnapFromDesignTool extends Tool
{
    use CreatesMcpSnaps;

    public function handle(Request $request, SnapService $snaps): Response
    {
        $validated = $request->validate([
            'layers' => ['bail', 'nullable', 'array', new ValidLayers, new SafeLayers],
            'type' => ['sometimes', 'in:image,pdf'],
            'format' => ['sometimes', 'in:png,jpeg,webp'],
            'transparent' => ['sometimes', 'boolean'],
        ] + CanvasRules::rules() + IdempotencyRules::rules(), CanvasRules::messages() + IdempotencyRules::messages());

        if ($error = $this->rejectUnsupportedTransparency($validated, $request)) {
            return $error;
        }

        $user = $request->user();

        $canvas = CanvasRules::resolve($validated);

        $design = InlineDesign::fromInput(
            array_values($validated['layers'] ?? []),
            $canvas['width'],
            $canvas['height'],
            $user->company_id,
        );

        try {
            $snap = $snaps->createFromDesign(
                $design,
                $validated['type'] ?? Snap::TYPE_IMAGE,
                $user->id,
                $user->company_id,
                $validated['format'] ?? null,
                $request->boolean('transparent'),
                $validated['idempotency_key'] ?? null,
            );
        } catch (IdempotencyConflictException $e) {
            return $this->idempotencyError($e);
        }

        $record = $this->recordUnlessReplayed($snap);

        return Response::text(json_encode($record + [
            'snap' => (new SnapResource($snap->append('imageUrl')))->resolve(),
            'note' => $record['replayed']
                ? 'This snap already existed for that idempotency_key. It was returned, not created again.'
                : 'Snap created from an inline design. No template was saved, so template_id and template_title are null. Keep the snap id to find the image again.',
        ], JSON_PRETTY_PRINT));
    }

    public function schema(JsonSchema $schema): array
    {
        return CanvasRules::schema($schema) + [
            'layers' => $schema->array()->description('Layer definitions (array of layer objects), the same shape create-template takes. Pass the exact array preview-design echoed back. Call describe-layers first for the property vocabulary and an example.'),
            'type' => $schema->string()
                ->enum(['image', 'pdf'])
                ->description('Output type. Defaults to image.'),
            'format' => $schema->string()
                ->enum(['png', 'jpeg', 'webp'])
                ->description('Image output format (image type only). Defaults to png. Transparency requires png or webp.'),
            'transparent' => $schema->boolean()
                ->description('Render on a transparent background (png/webp only). Defaults to false.'),
            'idempotency_key' => $schema->string()
                ->description('Optional. Send the same key when you retry after a timeout. The retry returns the same snap and does not render it again. Keys are honored for 24 hours, are scoped to your account and shared across every snap-creation tool, and accept letters, digits, underscores and hyphens, up to 191 characters.'),
        ];
    }
}
