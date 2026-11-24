<?php

namespace App\Exceptions;

use RuntimeException;

class EditorDocumentConflict extends RuntimeException
{
    /** The `message` of the REST 409 body. */
    public const MESSAGE = 'The document changed since you read it, so nothing was saved. Apply your change to `current_document`, then send it again with `current_revision`.';

    public function __construct(public readonly int $revision, public readonly ?array $document)
    {
        parent::__construct('editor_document_conflict');
    }
}
