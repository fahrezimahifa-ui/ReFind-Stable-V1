<?php

namespace App\Foundation;

use App\Exceptions\ContractError;
use Illuminate\Database\QueryException;

final class WriteFailure extends ContractError
{
    public function __construct(public readonly string $operationId, public readonly bool $uncertain, \Throwable $previous)
    {
        $driver = $previous instanceof QueryException ? (int) ($previous->errorInfo[1] ?? 0) : 0;
        $conflict = in_array($driver, [1205, 1213, 1062], true) || $previous instanceof LockUnavailable;
        parent::__construct($uncertain ? 'The outcome needs verification. Reload before attempting another change.' : ($conflict ? 'The record changed or is busy. Reload and retry.' : 'The request could not be completed.'), $uncertain ? 500 : ($conflict ? 409 : 500), $conflict && ! $uncertain ? 'state_conflict' : 'server_error', [], [], $previous);
    }
}
