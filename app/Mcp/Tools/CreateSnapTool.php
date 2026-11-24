<?php

namespace App\Mcp\Tools;

use App\Exceptions\IdempotencyConflictException;
use App\Http\Resources\SnapResource;
use App\Mcp\ToolError;
use App\Mcp\Tools\Concerns\CreatesMcpSnaps;
use App\Mcp\Tools\Concerns\InteractsWithSnaps;
use App\Models\Snap;
use App\Services\SnapService;
use App\Support\IdempotencyRules;
use App\Support\VariantRules;
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

#[Name('create-snap')]
#[Title('Create a snap')]
#[Description('Renders the FINAL, un-watermarked snap from a template and variants. Only call this after the human has approved a preview (preview-snap). Pass the same template_id and variants you previewed. Returns the snap id and the image URL. Idempotency keys are shared across every snap-creation tool.')]
// destructiveHint stays true although a create is additive. A final render
// cannot be undone, and this hint makes a client ask the human first.
#[IsDestructive]
#[IsOpenWorld]
#[IsReadOnly(false)]
class CreateSnapTool extends Tool
{
    use CreatesMcpSnaps, InteractsWithSnaps;

    public function handle(Request $request, SnapService $snaps): Response
    {
        $validated = $request->validate([
            'template_id' => ['required', 'string'],
            'type' => ['sometimes', 'in:image,pdf'],
            'format' => ['sometimes', 'in:png,jpeg,webp'],
            'transparent' => ['sometimes', 'boolean'],
        ] + IdempotencyRules::rules() + VariantRules::rules(), IdempotencyRules::messages());

        if ($error = $this->rejectUnsupportedTransparency($validated, $request)) {
            return $error;
        }

        $user = $request->user();

        $template = $this->companyTemplate($request, $validated['template_id']);

        if (! $template) {
            return ToolError::templateNotFound($validated['template_id']);
        }

        try {
            $snap = $snaps->create(
                $template,
                $request->get('variants', []) ?? [],
                $validated['type'] ?? Snap::TYPE_IMAGE,
                false,
                null,
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

        $ignored = $this->ignoredVariantLayers($template, $request->get('variants', []) ?? []);

        return Response::text(json_encode($record + [
            'snap' => (new SnapResource($snap->append('imageUrl')))->resolve(),
            'ignored_layers' => $ignored,
            'note' => $this->note($record['replayed'], $ignored),
        ], JSON_PRETTY_PRINT));
    }

    /**
     * A replay must not report that it created a snap. It returned one.
     */
    private function note(bool $replayed, array $ignored): string
    {
        if ($replayed) {
            return 'This snap already existed for that idempotency_key. It was returned, not created again.';
        }

        if (empty($ignored)) {
            return 'Snap created.';
        }

        return 'Snap created, but ignored_layers matched no layer on this template and had no effect. Read the layer keys with get-template-details before the next render.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'template_id' => $schema->string()
                ->description('UUID of the template to render.')
                ->required(),
            'type' => $schema->string()
                ->enum(['image', 'pdf'])
                ->description('Output type. Defaults to image.'),
            'format' => $schema->string()
                ->enum(['png', 'jpeg', 'webp'])
                ->description('Image output format (image type only). Defaults to png. Transparency requires png or webp.'),
            'transparent' => $schema->boolean()
                ->description('Render on a transparent background (png/webp only). Defaults to false.'),
            'idempotency_key' => $schema->string()
                ->description('Optional. Send the same key when you retry after a timeout. The retry returns the same snap and does not render it again. Keys are honored for 24 hours, are scoped to your account, and accept letters, digits, underscores and hyphens, up to 191 characters.'),
            'variants' => $this->variantsSchema($schema),
        ];
    }
}
