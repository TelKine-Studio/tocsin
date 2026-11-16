<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\AuditEntry;
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
                ->whereContains(['description', 'ip_address'], $search)
                ->orWhereHas('user', fn ($u) => $u->whereContains(['name', 'email'], $search))))
            ->latest()->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.audit', [
            'logs' => $logs,
            'days' => $logs->getCollection()
                ->map(fn (AuditLog $log) => new AuditEntry($log))
                ->groupBy(fn (AuditEntry $entry) => $entry->log->created_at->toDateString()),
            'search' => $search,
            'action' => $action,
            'groups' => AuditEntry::groups(),
        ]);
    }
}
