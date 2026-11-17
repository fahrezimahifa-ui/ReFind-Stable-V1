<?php

namespace App\Foundation;

use App\Database\PinnedMariaDbConnection;
use App\Exceptions\ContractError;
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
        if ($table === 'items' && $ids) {
            $rows = $this->connection->select('SELECT i.*,s.status_code FROM items i JOIN item_statuses s ON s.status_id=i.status_id WHERE i.item_id IN ('.implode(',', array_fill(0, count($ids), '?')).') ORDER BY i.item_id FOR UPDATE', $ids);
            if (count($rows) !== count($ids)) {
                throw new ContractError('A report changed or was removed. Reload and try again.', 409, 'state_conflict');
            }
            foreach ($rows as $row) {
                $out[(int) $row->item_id] = $row;
            }
        } else {
            foreach ($ids as $id) {
                $row = $this->connection->selectOne('SELECT * FROM `'.$table.'` WHERE `'.$key.'`=? FOR UPDATE', [$id]);
                if ($row) {
                    $out[$id] = $row;
                }
            }
        }
        $this->lockedIds = $ids;

        return $out;
    }
}
