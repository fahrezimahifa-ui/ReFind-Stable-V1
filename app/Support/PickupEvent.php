<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Read-only presentation of the private pickup audit (native app/pickup-read.php pickup_event at 47eea86).
 * The stored notification message is never rewritten; this only parses it for display.
 */
final class PickupEvent
{
    private static ?array $locations = null;

    private static array $actors = [];

    public static function parse(string $message): ?array
    {
        $state = '(PENDING|SCHEDULED|COMPLETED|RELEASED) / location (none|[1-9][0-9]*) / (unscheduled|[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z)';
        if (! preg_match('~^BM #([1-9][0-9]*) (schedule|reschedule|cancel_schedule|complete|release)\. Before: '.$state.'\. After: '.$state.'\.(?: Reason: (.*))?$~sD', $message, $parts)) {
            return null;
        }
        self::$locations ??= array_column(array_map(fn ($r) => (array) $r, DB::select('SELECT location_id,location_name FROM campus_locations')), 'location_name', 'location_id');
        $actorId = (int) $parts[1];
        if (! array_key_exists($actorId, self::$actors)) {
            self::$actors[$actorId] = DB::selectOne('SELECT full_name FROM users WHERE user_id=?', [$actorId])?->full_name;
        }
        $snapshot = fn (int $offset) => [
            'status' => $parts[$offset],
            'location' => $parts[$offset + 1] === 'none' ? null : (self::$locations[(int) $parts[$offset + 1]] ?? 'Previous pickup area'),
            'scheduled_at' => $parts[$offset + 2] === 'unscheduled' ? null : $parts[$offset + 2],
        ];

        return ['actor' => self::$actors[$actorId] ?? 'Building Management', 'operation' => $parts[2], 'before' => $snapshot(3), 'after' => $snapshot(6), 'reason' => $parts[9] ?? ''];
    }

    /** Native handover_revision: digest of the BM-authored pickup state (no claimant-authored fields). */
    public static function revision(array $handover): string
    {
        $values = [];
        foreach (['handover_id', 'claim_id', 'pickup_location_id', 'scheduled_at', 'completed_at', 'handled_by_user_id', 'notes', 'handover_status', 'updated_at'] as $key) {
            $values[$key] = (string) ($handover[$key] ?? '');
        }

        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }
}
