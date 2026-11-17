<?php

namespace App\Services;

use App\Database\PinnedMariaDbConnection;

final class ReportEffects
{
    public function __construct(private PinnedMariaDbConnection $db) {}

    public function saved(array $item, array &$locked, int $actor, bool $created): void {}

    public function deleted(array $item, array &$locked, int $actor): void {}
}
