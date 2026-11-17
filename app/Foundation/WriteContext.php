<?php

namespace App\Foundation;

use App\Database\PinnedMariaDbConnection;
use RuntimeException;

final class WriteContext
{
    public array $lockedIds = [];

    private array $held = [];

    public function __construct(public readonly PinnedMariaDbConnection $connection, public readonly string $operationId) {}

    public function lockItems(array $ids): array
    {
        return $this->ordered('items', 'item_id', $ids);
    }

    public function lockProbeRows(array $ids): array
    {
        return $this->ordered('_foundation_probe', 'id', $ids);
    }

    private function ordered(string $table, string $key, array $ids): array
    {
        $this->connection->assertPinned();
        if ($this->connection->transactionLevel() !== 1) {
            throw new RuntimeException('Row locks require the write transaction.');
        }
        foreach ($ids as $id) {
            if (! is_int($id) || $id < 1) {
                throw new RuntimeException('Invalid lock ID.');
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        $out = [];
        $previous = $this->held[$table] ?? [];
        foreach ($ids as $id) {
            if (! in_array($id, $previous, true) && $previous && $id < max($previous)) {
                throw new RuntimeException('Lock order would move backwards.');
            }
        }
        $this->held[$table] = array_values(array_unique([...$previous, ...$ids]));
        foreach ($ids as $id) {
            $row = $this->connection->selectOne('SELECT * FROM `'.$table.'` WHERE `'.$key.'`=? FOR UPDATE', [$id]);
            if ($row) {
                $out[$id] = $row;
            }
        }
        $this->lockedIds = $ids;

        return $out;
    }
}
