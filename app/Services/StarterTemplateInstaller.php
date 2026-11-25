<?php

namespace App\Services;

use App\Models\Template;
use App\Models\User;
use App\Support\StarterTemplates;
use App\Support\TemplateSizes;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Throwable;

/**
 * Adds the starter templates to the company of a user.
 *
 * Each template goes through the same steps as a template from the editor
 * or the MCP tools. TemplateDocumentService validates and stores the editor
 * document. TemplateVersioning publishes it. Template::generatePreviewImage()
 * renders the preview through the configured renderer.
 *
 * The installer is idempotent. It finds a starter template by its title in
 * the company, and it skips a title that already exists. A starter template
 * that a person deleted or renamed comes back at the next run.
 */
class StarterTemplateInstaller
{
    public function __construct(
        private TemplateDocumentService $documents,
        private TemplateVersioning $versioning,
    ) {}

    /**
     * With $renderPreviews false, the installer does not render the preview
     * image of each template. The caller then queues GenerateTemplatePreview.
     *
     * @return array{created: list<Template>, skipped: list<string>, preview_failures: list<string>}
     */
    public function install(User $user, bool $renderPreviews = true): array
    {
        if (! $user->company_id) {
            throw new InvalidArgumentException("The user {$user->email} has no company.");
        }

        if (Gate::forUser($user)->denies('create', Template::class)) {
            throw new AuthorizationException("The user {$user->email} cannot create templates.");
        }

        // Two runs for one company at the same time must not both create a
        // template. The lock makes the second run wait, and then it skips.
        return Cache::lock("starter-templates:company:{$user->company_id}", 300)
            ->block(60, fn () => $this->installMissing($user, $renderPreviews));
    }

    /**
     * @return array{created: list<Template>, skipped: list<string>, preview_failures: list<string>}
     */
    private function installMissing(User $user, bool $renderPreviews): array
    {
        $definitions = StarterTemplates::all();

        $existing = Template::query()
            ->where('company_id', $user->company_id)
            ->whereIn('title', array_column($definitions, 'title'))
            ->pluck('title')
            ->all();

        $result = ['created' => [], 'skipped' => [], 'preview_failures' => []];

        foreach ($definitions as $definition) {
            if (in_array($definition['title'], $existing, true)) {
                $result['skipped'][] = $definition['title'];

                continue;
            }

            [$template, $versionPreviewRendered] = $this->create($user, $definition);
            $result['created'][] = $template;

            if (! $versionPreviewRendered || ($renderPreviews && ! $this->renderPreview($template))) {
                $result['preview_failures'][] = $definition['title'];
            }
        }

        return $result;
    }

    /**
     * Returns the template, and false as the second item when the preview of
     * the published version did not render.
     *
     * @param  array{key: string, title: string, size: string, layers: list<array<string, mixed>>}  $definition
     * @return array{0: Template, 1: bool}
     */
    private function create(User $user, array $definition): array
    {
        $size = TemplateSizes::find($definition['size'])
            ?? throw new InvalidArgumentException("The size preset {$definition['size']} does not exist.");

        $template = DB::transaction(function () use ($user, $definition, $size) {
            $template = Template::create([
                'user_id' => $user->id,
                'company_id' => $user->company_id,
                'title' => $definition['title'],
                'width' => $size['width'],
                'height' => $size['height'],
                'size' => $size['value'],
                'layers' => json_encode($definition['layers'], JSON_THROW_ON_ERROR),
            ]);

            $this->documents->replace($template, 0, [
                'schema_version' => 2,
                'layers' => $definition['layers'],
                'groups' => [],
            ]);

            return $template->fresh();
        });

        // Publish writes the version, then dispatches the job that renders its
        // preview. On the sync queue that job runs inside this call, so a
        // renderer error comes back here after the version exists. Only an
        // error before the version exists is a failed publish.
        try {
            $this->versioning->publish($template);
            $versionPreviewRendered = true;
        } catch (Throwable $exception) {
            if (! $template->fresh()?->published_version_id) {
                throw $exception;
            }

            report($exception);
            $versionPreviewRendered = false;
        }

        return [$template->fresh(), $versionPreviewRendered];
    }

    /**
     * A failed preview leaves the template usable. Only the preview image in
     * the template list is missing. The error goes to the log.
     */
    private function renderPreview(Template $template): bool
    {
        try {
            $template->generatePreviewImage();

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
