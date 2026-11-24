<?php

namespace App\Services;

use LogicException;

/** The exact template version a snap must render from is not available to this company: it is gone or it belongs to another template or tenant. */
class SourceVersionUnavailableException extends LogicException {}
