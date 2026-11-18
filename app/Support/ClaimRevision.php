<?php

namespace App\Support;

use Illuminate\Database\ConnectionInterface;

/**
 * Opaque BM claim revision (64 lowercase hex, unchanged wire shape).
 *
 * Native 47eea86 hashes claim_id, item_id, related_lost_item_id, claimant_user_id, ownership_answer,
 * claim_status, review_note, reviewed_at, updated_at and the ordered evidence IDs. claims.updated_at has
 * second precision, so PENDING -> NEED_MORE_INFO -> PENDING (same answer, link and evidence) within one second
 * recreates an older token and a stale/repeated BM decision can pass. Deliberate, documented divergence
 * (pending sign-off): Laravel also hashes the latest claimant CLAIM_SUBMITTED notification ID ('submission',
 * 0 for legacy rows without one). Every submit/resubmit inserts exactly one, so each claimant revision is
 * distinct. Compare per-system token behaviour, never raw token equality with native.
 */
final class ClaimRevision
{
    public const NATIVE_KEYS = ['claim_id', 'item_id', 'related_lost_item_id', 'claimant_user_id', 'ownership_answer', 'claim_status', 'review_note', 'reviewed_at', 'updated_at', 'evidence'];

    /** @param array $claim raw claim row values (DB strings, not ISO dates) */
    public static function digest(array $claim, array $evidenceIds, ?int $submission): string
    {
        $claim['evidence'] = json_encode(array_map('intval', $evidenceIds), JSON_THROW_ON_ERROR);
        $keys = self::NATIVE_KEYS;
        if ($submission !== null) {
            $claim['submission'] = $submission;
            $keys[] = 'submission';
        }
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = (string) ($claim[$key] ?? '');
        }

        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    /** Native-equivalent digest, used only by the same-second probe and parity notes. */
    public static function native(array $claim, array $evidenceIds): string
    {
        return self::digest($claim, $evidenceIds, null);
    }

    public static function compute(ConnectionInterface $db, array $claim): string
    {
        $evidence = array_map(fn ($row) => (int) $row->claim_photo_id, $db->select('SELECT claim_photo_id FROM claim_photos WHERE claim_id=? ORDER BY claim_photo_id', [(int) $claim['claim_id']]));
        $submission = (int) $db->selectOne("SELECT COALESCE(MAX(notification_id),0) AS id FROM notifications WHERE claim_id=? AND user_id=? AND notification_type='CLAIM_SUBMITTED'", [(int) $claim['claim_id'], (int) $claim['claimant_user_id']])->id;

        return self::digest($claim, $evidence, $submission);
    }
}
