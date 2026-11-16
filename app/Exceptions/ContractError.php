<?php

namespace App\Exceptions;

final class ContractError extends \RuntimeException
{
    public function __construct(string $message, public int $status = 422, public string $errorCode = 'validation_error', public array $fields = [], public array $headers = [])
    {
        parent::__construct($message);
    }
}
