<?php

namespace App\Mcp\Tools;

use App\Support\EditorDocumentContract;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('describe-editor-document')]
#[Title('Describe the v2 editor document')]
#[Description('Returns the versioned v2 editor-document contract, including apply-editor-operations grammar, limits, conflict recovery, and deferred capabilities. Read it before any editor-document mutation.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class DescribeEditorDocumentTool extends Tool
{
    public function handle(Request $request): Response
    {
        return Response::text(json_encode(EditorDocumentContract::catalog(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
