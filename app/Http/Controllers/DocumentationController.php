<?php

namespace App\Http\Controllers;

use App\Support\ApiDocs\ApiSpec;
use App\Support\ApiDocs\LlmsTxtGenerator;
use App\Support\ApiDocs\MarkdownGenerator;
use App\Support\ApiDocs\OpenApiGenerator;
use App\Support\ApiDocs\PostmanGenerator;
use App\Support\ApiDocs\SkillFileGenerator;
use Illuminate\Http\Response;

/**
 * Serves the API documentation. Every surface — the in-app screens, the
 * OpenAPI schema, the Postman collection and the Markdown exports — is built
 * on the fly from {@see ApiSpec}, the single source of truth.
 */
class DocumentationController extends Controller
{
    /** Docs landing page. */
    public function index()
    {
        return view('documentation.index', [
            'groups' => ApiSpec::groups(),
            'info' => ApiSpec::info(),
        ]);
    }

    /** A static info page rendered from its own Blade view. */
    public function page(string $view)
    {
        abort_unless(view()->exists("documentation.{$view}"), 404);

        return view("documentation.{$view}");
    }

    /** A spec-driven endpoint group screen. */
    public function group(string $slug)
    {
        $group = ApiSpec::group($slug);
        abort_if($group === null, 404);

        return view('documentation.group', [
            'group' => $group,
            'info' => ApiSpec::info(),
        ]);
    }

    /** The full object + endpoint reference. */
    public function reference()
    {
        return view('documentation.reference', [
            'groups' => ApiSpec::groups(),
            'schemas' => ApiSpec::schemas(),
            'info' => ApiSpec::info(),
        ]);
    }

    /** Download the OpenAPI 3.0 schema (YAML), generated on the fly. */
    public function openapi(): Response
    {
        return response((new OpenApiGenerator)->toYaml(), 200, [
            'Content-Type' => 'application/yaml',
            'Content-Disposition' => 'attachment; filename="snapsbrew-openapi.yaml"',
        ]);
    }

    /** Download the Postman collection (JSON), generated on the fly. */
    public function postman(): Response
    {
        return response((new PostmanGenerator)->toJson(), 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="snapsbrew-api.postman_collection.json"',
        ]);
    }

    /** Download a docs screen, or the full reference, as Markdown. */
    public function markdown(string $slug): Response
    {
        $generator = new MarkdownGenerator;

        if ($slug === 'reference' || $slug === 'full') {
            $body = $generator->full();
            $filename = 'snapsbrew-api-reference.md';
        } else {
            $body = $generator->group($slug);
            abort_if($body === null, 404);
            $filename = "snapsbrew-{$slug}.md";
        }

        return response($body, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * `llms.txt` — the convention that points a model at a site's documentation.
     */
    public function llms(): Response
    {
        return response((new LlmsTxtGenerator)->toText(url('/')), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    /**
     * `skill.md` — an Agent Skill file that carries the MCP workflow.
     */
    public function skill(): Response
    {
        return response((new SkillFileGenerator)->toMarkdown(url('/')), 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
        ]);
    }
}
