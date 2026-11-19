<?php

namespace App\Repositories;

use App\Exceptions\ContractError;
use App\Services\AuthService;
use App\Support\Contract;
use App\Support\PickupEvent;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/handovers.php (native api/handovers.php at 47eea86). Students see only pickups for their own claims,
 * without contact fields; BM additionally receives the revision, available operations and, for an exact record,
 * the private pickup audit copied to the claimant.
 */
final class HandoverReadRepository
{
    public function __construct(private ItemReadRepository $items, private AuthService $auth) {}

    public function listing(array $query, array $user): array
    {
        $where = [];
        $values = [];
        if ($user['role'] === 'STUDENT') {
            $where[] = 'c.claimant_user_id = ?';
            $values[] = (int) $user['id'];
        }
        if (isset($query['id'])) {
            $where[] = 'h.handover_id = ?';
            $values[] = Contract::id($query['id']);
        }
        if (isset($query['status']) && $query['status'] !== '' && $query['status'] !== 'all') {
            $state = Contract::text($query, 'status', 20);
            if ($state === 'active') {
                $where[] = "h.handover_status IN ('PENDING','SCHEDULED')";
            } else {
                if (! in_array($state, ['PENDING', 'SCHEDULED', 'COMPLETED'], true)) {
                    throw new ContractError('Invalid pickup state.', 400);
                }
                $where[] = 'h.handover_status=?';
                $values[] = $state;
            }
        }
        $search = Contract::text($query, 'q', 200, false);
        if ($search !== '') {
            $where[] = "LOCATE(?,CONCAT_WS(' ',i.title,u.full_name,l.location_name))>0";
            $values[] = $search;
        }
        $from = ' FROM handovers h JOIN claims c ON c.claim_id=h.claim_id JOIN items i ON i.item_id=c.item_id JOIN users u ON u.user_id=c.claimant_user_id LEFT JOIN campus_locations l ON l.location_id=h.pickup_location_id';
        $clause = $where ? ' WHERE '.implode(' AND ', $where) : '';
        [$page, $limit, $offset] = $this->items->pagination($query, 24);
        $total = (int) DB::selectOne('SELECT COUNT(*) AS n'.$from.$clause, $values)->n;
        $records = array_map(fn ($r) => (array) $r, DB::select('SELECT h.*,c.item_id,c.related_lost_item_id,c.claim_status,c.claimant_user_id,u.full_name AS claimant_name,u.avatar_path AS claimant_avatar_path,u.email AS claimant_email,u.student_id AS claimant_student_id,i.title,l.location_name AS pickup_location'.$from.$clause.' ORDER BY h.updated_at DESC,h.handover_id DESC LIMIT ? OFFSET ?', [...$values, $limit, $offset]));
        if (isset($query['id']) && ! $records) {
            throw new ContractError('Pickup record not found.', 404, 'not_found');
        }
        foreach ($records as &$record) {
            $record['claimant_avatar_url'] = $this->auth->avatar($record['claimant_avatar_path']);
            unset($record['claimant_avatar_path']);
            foreach (['handover_id', 'claim_id', 'item_id'] as $key) {
                $record[$key] = (string) $record[$key];
            }
            foreach (['pickup_location_id', 'related_lost_item_id', 'handled_by_user_id'] as $key) {
                $record[$key] = $record[$key] !== null ? (string) $record[$key] : null;
            }
            if ($user['role'] === 'BUILDING_MANAGEMENT') {
                $record['revision'] = PickupEvent::revision($record);
                $record['operations'] = $record['claim_status'] === 'APPROVED' ? match ($record['handover_status']) {
                    'PENDING' => ['schedule', 'complete', 'release'],
                    'SCHEDULED' => ['reschedule', 'cancel_schedule', 'complete', 'release'],
                    default => [],
                } : [];
                // Every pickup change is copied to the claimant, so their copy is the complete private audit for any BM viewer.
                if (isset($query['id'])) {
                    $record['audit'] = array_map(fn ($e) => ['at' => $this->items->utcIso($e->created_at), 'title' => $e->title, 'message' => $e->message, 'pickup_event' => PickupEvent::parse($e->message)],
                        DB::select("SELECT created_at,title,message FROM notifications WHERE user_id=? AND claim_id=? AND LEFT(notification_type,9)='HANDOVER_' ORDER BY notification_id", [(int) $record['claimant_user_id'], (int) $record['claim_id']]));
                }
            } else {
                unset($record['claimant_email'], $record['claimant_student_id']);
            }
            unset($record['claimant_user_id']);
            foreach (['scheduled_at', 'completed_at', 'created_at', 'updated_at'] as $key) {
                $record[$key] = $this->items->utcIso($record[$key]);
            }
            unset($record['claim_status']); // Pickup state drives queue colour/actions; claim approval is invariant.
        }
        unset($record);

        return ['handovers' => $records, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $limit))];
    }
}
