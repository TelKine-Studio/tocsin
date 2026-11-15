<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Topic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Browser notifications: the web interface subscribes this browser to a topic.
 */
class WebPushController extends Controller
{
    public function update(Request $request, Topic $topic): JsonResponse
    {
        $this->authorize('view', $topic);

        $data = $request->validate([
            'subscription.endpoint' => ['required', 'url:https,http', 'max:2000'],
            'subscription.keys.p256dh' => ['required', 'string', 'max:200'],
            'subscription.keys.auth' => ['required', 'string', 'max:100'],
            'enabled' => ['required', 'boolean'],
        ]);

        $device = Device::query()->updateOrCreate(
            ['token' => hash('sha256', $data['subscription']['endpoint'])],
            [
                'user_id' => $request->user()->id,
                'platform' => 'web',
                'environment' => 'production',
                'web_subscription' => json_encode($data['subscription']),
                'name' => mb_substr((string) $request->userAgent(), 0, 100),
                'last_seen_at' => now(),
            ],
        );

        $data['enabled'] ? $device->topics()->syncWithoutDetaching([$topic->id]) : $device->topics()->detach($topic->id);

        return response()->json(['enabled' => $device->topics()->whereKey($topic->id)->exists()]);
    }

    /** Topics this browser is subscribed to. */
    public function status(Request $request): JsonResponse
    {
        $request->validate(['endpoint' => ['required', 'string', 'max:2000']]);

        $device = Device::query()->where('token', hash('sha256', $request->input('endpoint')))->where('user_id', $request->user()->id)->first();

        return response()->json(['topics' => $device?->topics()->pluck('name') ?? []]);
    }
}
