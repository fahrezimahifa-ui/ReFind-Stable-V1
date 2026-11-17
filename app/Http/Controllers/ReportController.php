<?php

namespace App\Http\Controllers;

use App\Services\AuthService;
use App\Services\ReportService;
use App\Support\Contract;
use Illuminate\Http\Request;

final class ReportController
{
    public function mutate(Request $request, AuthService $auth, ReportService $reports)
    {
        $u = $auth->require('STUDENT');
        Contract::allowed($request->query(), ['id', 'mine', 'editable', 'q', 'type', 'category_id', 'location_id', 'status', 'date', 'closed', 'active', 'page', 'limit']);
        $result = $reports->mutate(['user_id' => (string) $u->user_id, 'role' => $u->role], $request);

        return Contract::json($result, $result['action'] === 'create' ? 201 : 200);
    }
}
