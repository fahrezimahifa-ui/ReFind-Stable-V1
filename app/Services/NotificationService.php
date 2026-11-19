<?php

namespace App\Services;

use App\Exceptions\ContractError;
use App\Foundation\MediaJournal;
use App\Foundation\WriteContext;
use App\Foundation\WriteCoordinator;
use App\Support\ClaimStages;
use App\Support\Contract;
use Illuminate\Http\Request;

/** Phase 8 notification read-marking (native api/notifications.php POST at 47eea86); recipients only. */
final class NotificationService
{
    public function __construct(private MediaJournal $journal, private WriteCoordinator $writes) {}

    public function mark(array $user, Request $request): array
    {
        if (! ClaimStages::has('notifications')) {
            throw new ContractError('This operation is not available in the proof of concept.', 501, 'feature_pending');
        }
        $query = $request->query();
        Contract::allowed($query, ['page', 'limit', 'count', 'unread']);
        if (isset($query['unread']) && $query['unread'] !== '1') {
            throw new ContractError('Invalid notification filter.', 400);
        }
        $data = $request->request->all();
        Contract::allowed($data, ['id', 'action', '_csrf']);
        $all = ($data['action'] ?? null) === 'read_all';
        if (! $all && isset($data['action'])) {
            throw new ContractError('Invalid notification action.', 400, 'invalid_request');
        }
        $id = $all ? null : Contract::id($data['id'] ?? null);
        $owner = (int) $user['user_id'];

        return $this->writes->run($this->journal->create(), function (WriteContext $context) use ($all, $id, $owner) {
            $db = $context->connection;
            if ($all) {
                $db->update('UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0', [$owner]);
            } else {
                if (! $db->selectOne('SELECT notification_id FROM notifications WHERE notification_id=? AND user_id=?', [$id, $owner])) {
                    throw new ContractError('Notification not found.', 404, 'not_found');
                }
                $db->update('UPDATE notifications SET is_read=1 WHERE notification_id=? AND user_id=?', [$id, $owner]);
            }

            return ['unread_count' => (int) $db->selectOne('SELECT COUNT(*) AS n FROM notifications WHERE user_id=? AND is_read=0', [$owner])->n];
        });
    }
}
