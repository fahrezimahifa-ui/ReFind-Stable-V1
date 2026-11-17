<?php

namespace App\Exceptions;

class ContractError extends \RuntimeException
{
    public function __construct(string $message, public int $status = 422, public string $errorCode = 'validation_error', public array $fields = [], public array $headers = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
