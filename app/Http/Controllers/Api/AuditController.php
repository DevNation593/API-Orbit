<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class AuditController extends Controller
{
    public function index(): JsonResponse
    {
        abort_unless(request()->user()->hasPermission('audit.view'), 403, 'You do not have permission to view audit logs.');
        $logs = AuditLog::query()->with('user:id,name,email')->orderByDesc('created_at')->paginate(ApiResponse::perPage(request('per_page', 50), 50));

        return ApiResponse::paginated($logs);
    }
}
