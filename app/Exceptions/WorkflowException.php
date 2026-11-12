<?php

namespace App\Exceptions;

use RuntimeException;

/** A business rule was violated (wrong state, over-issue, insufficient stock...). Shown to the user as a validation-style error. */
class WorkflowException extends RuntimeException
{
}
