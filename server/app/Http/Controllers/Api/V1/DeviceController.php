<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Topic;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Push registration of mobile devices.
 *
 * The app sends its full state (APNs token + subscribed topics) every time
 * it changes; the call is idempotent. The device belongs to the
 * authenticated user, if any, which is required for private topics.
 */
class DeviceController extends Controller
{
    public function update(Request $request, Settings $settings): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:500', Rule::when($request->input('platform') === 'ios', ['regex:/^[0-9a-fA-F]{32,200}$/'])],
            'platform' => ['required', Rule::in(['ios', 'android'])],
            'environment' => ['required', Rule::in(Device::ENVIRONMENTS)],
            'name' => ['nullable', 'string', 'max:100'],
            'app_version' => ['nullable', 'string', 'max:32'],
            'topics' => ['present', 'array', 'max:500'],
            'topics.*' => ['string', 'max:64'],
        ]);

        $user = $request->user();

        $device = Device::query()->updateOrCreate(
            ['token' => $data['platform'] === 'ios' ? strtolower($data['token']) : $data['token']],
            [
                'user_id' => $user?->id,
                'platform' => $data['platform'],
                'environment' => $data['environment'],
                'name' => $data['name'] ?? null,
                'app_version' => $data['app_version'] ?? null,
                'last_seen_at' => now(),
            ],
        );

        $names = collect($data['topics'])->map(fn (string $name) => mb_strtolower(trim($name)))->unique();
        $topics = Topic::query()->whereIn('name', $names)->with('members')->get()->keyBy('name');

        $accepted = [];
        $rejected = [];

        foreach ($names as $name) {
            $topic = $topics->get($name);

            if (! $topic) {
                $rejected[] = ['topic' => $name, 'reason' => 'not_found'];
            } elseif (! $topic->canRead($user)) {
                $rejected[] = ['topic' => $name, 'reason' => $user ? 'forbidden' : 'unauthenticated'];
            } else {
                $accepted[$topic->id] = $name;
            }
        }

        $device->topics()->sync(array_keys($accepted));

        return response()->json([
            'data' => [
                'push_enabled' => $settings->pushConfigured(),
                'topics' => array_values($accepted),
                'rejected' => $rejected,
            ],
        ]);
    }

    public function destroy(Request $request): Response
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:200']]);

        Device::query()->where('token', strtolower($data['token']))->delete();

        return response()->noContent();
    }
}
