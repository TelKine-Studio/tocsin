<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RelayClient;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Instances using this server as push relay (relay operators only).
 */
class RelayClientController extends Controller
{
    public function index(Request $request, Settings $settings): View
    {
        $search = trim((string) $request->query('q'));

        $clients = RelayClient::query()
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->whereContains(['name', 'url', 'email'], $search)))
            ->with('todayUsage')
            ->withSum(['usages as sent_30_days' => fn ($q) => $q->where('date', '>=', now()->subDays(30)->toDateString())], 'sent')
            ->latest('last_used_at')
            ->paginate(25)
            ->withQueryString();

        return view('admin.relay-clients.index', [
            'clients' => $clients,
            'search' => $search,
            'plans' => RelayClient::PLANS,
            'freeLimit' => $settings->integer('relay_server.free_daily_limit'),
            'enabled' => $settings->boolean('relay_server.enabled'),
        ]);
    }

    public function update(Request $request, RelayClient $relayClient): RedirectResponse
    {
        $data = $request->validate([
            'plan' => ['required', Rule::in(RelayClient::PLANS)],
            'daily_limit' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ]);

        $relayClient->update([...$data, 'is_active' => $request->boolean('is_active')]);

        return back()->with('status', __('Instance updated.'));
    }

    public function rotate(RelayClient $relayClient): RedirectResponse
    {
        $key = $relayClient->rotateKey();

        return back()
            ->with('status', __('New key generated for :name. The previous key no longer works.', ['name' => $relayClient->name]))
            ->with('relay_key', $key);
    }

    public function destroy(RelayClient $relayClient): RedirectResponse
    {
        $relayClient->delete();

        return back()->with('status', __('Instance removed from the relay.'));
    }
}
