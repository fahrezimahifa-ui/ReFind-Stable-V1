<?php

namespace App\Repositories;

use App\Support\PickupEvent;
use Illuminate\Support\Facades\DB;

/**
 * Read-only notification list (native api/notifications.php GET at 47eea86), exposed in Phase 7 so claimants and
 * other BMs can read pickup audit messages. Marking notifications read is a separate, unapproved write.
 */
final class NotificationReadRepository
{
    public function __construct(private ItemReadRepository $items) {}

    public function listing(array $query, array $user, bool $onlyUnread): array
    {
        [$page, $limit, $offset] = $this->items->pagination($query, 24);
        $unreadClause = $onlyUnread ? ' AND n.is_read=0' : '';
        $records = array_map(fn ($r) => (array) $r, DB::select('SELECT n.notification_id,n.notification_type,n.title,n.message,n.item_id,n.claim_id,n.is_read,n.created_at,h.handover_id,c.claimant_user_id,i.title AS item_title FROM notifications n LEFT JOIN claims c ON c.claim_id=n.claim_id LEFT JOIN handovers h ON h.claim_id=c.claim_id LEFT JOIN items i ON i.item_id=n.item_id WHERE n.user_id=?'.$unreadClause.' ORDER BY n.created_at DESC,n.notification_id DESC LIMIT ? OFFSET ?', [(int) $user['id'], $limit, $offset]));
        foreach ($records as &$record) {
            $record['created_at'] = $this->items->utcIso($record['created_at']);
            $authorized = $user['role'] === 'BUILDING_MANAGEMENT' || (string) ($record['claimant_user_id'] ?? '') === (string) $user['id'];
            $record['handover_id'] = $authorized && $record['handover_id'] !== null ? (string) $record['handover_id'] : null;
            $record['pickup_event'] = str_starts_with($record['notification_type'], 'HANDOVER_') ? PickupEvent::parse($record['message']) : null;
            unset($record['claimant_user_id']);
        }
        unset($record);
        $total = (int) DB::selectOne('SELECT COUNT(*) AS n FROM notifications n WHERE n.user_id=?'.$unreadClause, [(int) $user['id']])->n;

        return ['notifications' => $records, 'unread_count' => (int) DB::selectOne('SELECT COUNT(*) AS n FROM notifications WHERE user_id=? AND is_read=0', [(int) $user['id']])->n, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $limit))];
    }
}
