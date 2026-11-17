<?php

namespace App\Http\Controllers;

use App\Enums\Priority;
use App\Enums\TopicVisibility;
use App\Http\Controllers\Ntfy\NtfyController;
use App\Models\Topic;
use App\Models\User;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TopicController extends Controller
{
    /** Grouping windows offered in the topic settings, in minutes. */
    public const GROUP_WINDOWS = [0, 5, 10, 30, 60, 240, 1440];

    public const MAX_ESCALATION_STEPS = 5;

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));
        $visibility = TopicVisibility::tryFrom((string) $request->query('visibility'));

        $topics = Topic::query()
            ->readableBy($request->user())
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->whereContains(['name', 'display_name', 'description'], $search)))
            ->when($visibility, fn ($query) => $query->where('visibility', $visibility->value))
            ->with('owner')
            ->withCount(['messages', 'members', 'devices'])
            ->orderByRaw('last_message_at is null')
            ->orderByDesc('last_message_at')
            ->orderBy('name')
            ->paginate(24)
            ->withQueryString();

        return view('topics.index', compact('topics', 'search', 'visibility'));
    }

    public function create(): View
    {
        $this->authorize('create', Topic::class);

        return view('topics.create', [
            'topic' => new Topic(['visibility' => TopicVisibility::Private, 'group_window' => 10]),
            'escalationUsers' => $this->escalationUsers(null),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Topic::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'regex:'.Topic::NAME_PATTERN, Rule::notIn(NtfyController::RESERVED), 'unique:topics,name'],
            ...$this->rules(),
        ], [
            'name.regex' => __('Use lowercase letters, digits, dashes and underscores only (max. 64 characters).'),
        ]);

        $topic = Topic::create([
            ...$this->normalize($data),
            'anonymous_publish' => $request->boolean('anonymous_publish'),
            'owner_id' => $request->user()->id,
        ]);

        $this->saveIcon($request, $topic);

        Audit::log('topic.created', $topic, ['visibility' => $topic->visibility->value]);

        return redirect()->route('topics.show', $topic)->with('status', __('Topic created.'));
    }

    public function show(Request $request, Topic $topic, Settings $settings): View
    {
        $this->authorize('view', $topic);

        $topic->load(['owner', 'members'])->loadCount(['messages', 'devices']);

        $messages = $topic->messages()
            ->delivered()
            ->with(['user', 'acknowledger'])
            ->latest('id')
            ->paginate(25);

        return view('topics.show', [
            'topic' => $topic,
            'messages' => $messages,
            'webhooks' => $request->user()->can('update', $topic) ? $topic->webhooks()->latest()->get() : collect(),
            'scheduled' => $request->user()->can('update', $topic)
                ? $topic->messages()->whereNull('dispatched_at')->orderBy('scheduled_at')->get()
                : collect(),
            'serverUrl' => rtrim($settings->get('app.url') ?: url('/'), '/'),
            // The directory of the instance is only shown to its administrators:
            // topic owners type the email of the person they invite.
            'users' => $request->user()->isAdmin()
                ? User::query()->where('is_active', true)->whereKeyNot($topic->owner_id)->whereNotIn('id', $topic->members->modelKeys())->orderBy('name')->get(['id', 'name', 'email'])
                : collect(),
            'priorities' => Priority::cases(),
            'canPublish' => $request->user()->can('publish', $topic),
            'canManage' => $request->user()->can('update', $topic),
            'apiUrl' => route('api.v1.messages.store', $topic),
            'maxLength' => $settings->integer('messages.max_length'),
        ]);
    }

    public function edit(Topic $topic): View
    {
        $this->authorize('update', $topic);

        return view('topics.edit', ['topic' => $topic, 'escalationUsers' => $this->escalationUsers($topic)]);
    }

    public function update(Request $request, Topic $topic): RedirectResponse
    {
        $this->authorize('update', $topic);

        $topic->update([
            ...$this->normalize($request->validate($this->rules())),
            'anonymous_publish' => $request->boolean('anonymous_publish'),
        ]);

        $this->saveIcon($request, $topic);

        Audit::log('topic.updated', $topic, ['visibility' => $topic->visibility->value, 'anonymous_publish' => $topic->anonymous_publish]);

        return redirect()->route('topics.show', $topic)->with('status', __('Topic updated.'));
    }

    public function destroy(Topic $topic): RedirectResponse
    {
        $this->authorize('delete', $topic);

        Audit::log('topic.deleted', $topic);
        $topic->delete();

        return redirect()->route('topics.index')->with('status', __('Topic deleted.'));
    }

    /** @return array<string, array<int, mixed>> */
    protected function rules(): array
    {
        return [
            'display_name' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'visibility' => ['required', Rule::enum(TopicVisibility::class)],
            'group_window' => ['nullable', 'integer', Rule::in(self::GROUP_WINDOWS)],
            'ack_priority' => ['nullable', 'integer', Rule::in([1, 3, 4, 5])],
            'escalation' => ['nullable', 'array', 'max:'.self::MAX_ESCALATION_STEPS],
            'escalation.*.after' => ['required', 'integer', 'between:1,1440', 'distinct'],
            'escalation.*.users' => ['nullable', 'array'],
            'escalation.*.users.*' => ['integer', Rule::exists('users', 'id')],
            'icon' => ['nullable', 'file', 'mimetypes:'.implode(',', array_keys(Topic::ICON_TYPES)), 'max:'.Topic::ICON_MAX_KB, 'dimensions:min_width=64,min_height=64,max_width=4096,max_height=4096'],
            'remove_icon' => ['nullable', 'boolean'],
        ];
    }

    /** Stores the uploaded logo (or removes it), replacing the previous file. */
    protected function saveIcon(Request $request, Topic $topic): void
    {
        $file = $request->file('icon');

        if (! $file && ! $request->boolean('remove_icon')) {
            return;
        }

        if ($topic->icon_path) {
            Storage::disk('local')->delete($topic->icon_path);
        }

        $path = $file?->storeAs('topic-icons', $topic->id.'-'.Str::lower(Str::random(10)).'.'.Topic::ICON_TYPES[$file->getMimeType()], 'local');

        $topic->forceFill(['icon_path' => $path ?: null])->save();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalize(array $data): array
    {
        unset($data['icon'], $data['remove_icon']);

        if (($data['group_window'] ?? null) === null) {
            unset($data['group_window']);
        } else {
            $data['group_window'] = (int) $data['group_window'];
        }

        $data['ack_priority'] = filled($data['ack_priority'] ?? null) ? (int) $data['ack_priority'] : null;

        $steps = collect($data['escalation'] ?? [])
            ->map(fn (array $step) => ['after' => (int) $step['after'], 'users' => array_values(array_unique(array_map('intval', $step['users'] ?? [])))])
            ->sortBy('after')
            ->values()
            ->all();

        // Reminders only make sense when an acknowledgement is expected.
        $data['escalation'] = $data['ack_priority'] !== null && $steps !== [] ? $steps : null;

        return $data;
    }

    /**
     * People who can be emailed by the escalation: every active user for
     * administrators, otherwise the owner and the members of the topic.
     *
     * @return Collection<int, User>
     */
    protected function escalationUsers(?Topic $topic): Collection
    {
        $user = request()->user();

        $query = User::query()->where('is_active', true)->orderBy('name');

        if (! $user->isAdmin()) {
            $ids = $topic ? $topic->members()->pluck('users.id')->push($topic->owner_id) : collect();
            $query->whereKey($ids->push($user->id)->unique()->all());
        }

        return $query->get(['id', 'name', 'email']);
    }
}
