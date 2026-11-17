<?php

namespace App\Policies;

use App\Exceptions\ContractError;
use Illuminate\Support\Facades\DB;

final class ReportPolicy
{
    public function editable(array $user, array $item): void
    {
        if (($user['role'] ?? null) !== 'STUDENT') {
            throw new ContractError('This action is not available to your role.', 403, 'forbidden');
        }
        if ((string) $item['reporter_user_id'] !== $user['user_id']) {
            throw new ContractError('Only the reporter can change this report.', 403, 'forbidden');
        }
        $id = (int) $item['item_id'];
        if (! in_array($item['status_code'], ['OPEN', 'POTENTIAL_MATCH'], true) || DB::selectOne("SELECT claim_id FROM claims WHERE (item_id=? OR related_lost_item_id=?) AND claim_status IN ('PENDING','NEED_MORE_INFO','APPROVED') LIMIT 1", [$id, $id]) || DB::selectOne("SELECT match_id FROM item_matches WHERE match_status='CONFIRMED' AND (lost_item_id=? OR found_item_id=?) LIMIT 1", [$id, $id])) {
            throw new ContractError('This report is locked by its workflow.', 409, 'state_conflict');
        }
    }
}
