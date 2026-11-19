<?php

namespace App\Exceptions;

use RuntimeException;

class DocumentProcessingException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $transient = false)
    {
        parent::__construct($message);
    }
}
