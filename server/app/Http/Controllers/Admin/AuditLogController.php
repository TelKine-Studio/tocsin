<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function __invoke(Request $request): View
    {
        $search = trim((string) $request->query('q'));
        $action = (string) $request->query('action');

        $logs = AuditLog::query()
            ->with('user')
            ->when($action !== '', fn ($query) => $query->where('action', 'like', $action.'%'))
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('description', 'like', "%{$search}%")
                ->orWhere('ip_address', 'like', "%{$search}%")
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.audit', [
            'logs' => $logs,
            'search' => $search,
            'action' => $action,
            'groups' => ['' => __('All events'), 'auth' => __('Authentication'), 'user' => __('Users'), 'token' => __('Tokens'), 'topic' => __('Topics'), 'webhook' => __('Webhooks'), 'settings' => __('Settings'), 'relay' => __('Relay')],
        ]);
    }
}
