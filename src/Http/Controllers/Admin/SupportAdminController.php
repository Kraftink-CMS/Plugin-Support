<?php

namespace KraftInk\Plugins\Support\Http\Controllers\Admin;

use App\Kraft\System\TableQuery;
use App\Kraft\Html;
use App\Kraft\Secrets;
use App\Kraft\Settings;
use App\Kraft\System\SensitiveAction;
use App\Kraft\System\WebhookClient;
use App\Models\Accounts\Role;
use App\Models\Accounts\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use KraftInk\Plugins\Support\Models\Category;
use KraftInk\Plugins\Support\Models\CategoryRole;
use KraftInk\Plugins\Support\Models\Field;
use KraftInk\Plugins\Support\Models\ReplyTemplate;
use KraftInk\Plugins\Support\Models\Ticket;
use KraftInk\Plugins\Support\Services\SupportAccess;
use KraftInk\Plugins\Support\Services\SupportTrash;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use App\Kraft\Notifications\Notifications;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SupportAdminController
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => 'nullable|string|max:200',
            'status' => 'nullable|string',
            'priority' => 'nullable|string',
            'category_id' => 'nullable|integer',
            'assigned_to' => ['nullable', Rule::when($request->input('assigned_to') !== 'unassigned', 'integer')],
            'user_id' => 'nullable|integer|min:1',
        ]);
        $authorId = $filters['user_id'] ?? null;
        $query = Ticket::query()
            ->whereNull('support__tickets.deleted_at')
            ->when($authorId !== null, fn ($query) => $query->where('support__tickets.user_id', $authorId));
        $counts = clone $query;
        $query->leftJoin('users', 'users.id', '=', 'support__tickets.user_id')
            ->join('support__categories', 'support__categories.id', '=', 'support__tickets.category_id')
            ->select('support__tickets.*', 'users.username as author_username', 'support__categories.name as category')
            ->orderByDesc('support__tickets.updated_at');
        foreach (['priority', 'category_id'] as $filter) {
            if (isset($filters[$filter]) && $filters[$filter] !== '') {
                $query->where('support__tickets.'.$filter, $filters[$filter]);
            }
        }
        $status = $filters['status'] ?? null;
        if ($status === 'active') {
            $query->where('support__tickets.status', '!=', 'closed');
        } elseif ($status !== null && $status !== '') {
            $query->where('support__tickets.status', $status);
        }
        $assignee = $filters['assigned_to'] ?? null;
        if ($assignee === 'unassigned') {
            $query->whereNull('support__tickets.assigned_to');
        } elseif ($assignee !== null && $assignee !== '') {
            $query->where('support__tickets.assigned_to', $assignee);
        }
        if ($request->filled('q')) {
            $search = trim($request->input('q'));
            $query->where(function ($query) use ($search) {
                TableQuery::whereContains($query, ['support__tickets.subject', 'users.username'], $search);
                $query->orWhereExists(function ($messages) use ($search) {
                    $messages
                        ->selectRaw('1')
                        ->from('support__messages')
                        ->whereColumn('ticket_id', 'support__tickets.id');
                    TableQuery::whereContains($messages, ['body'], $search);
                });
            });
        }
        if ($request->boolean('overdue')) {
            $query
                ->where('status', '!=', 'closed')
                ->where(
                    fn ($query) => $query
                        ->where(
                            fn ($first) => $first
                                ->whereNull('first_staff_reply_at')
                                ->where('first_response_due_at', '<', now()),
                        )
                        ->orWhere('resolution_due_at', '<', now()),
                );
        }

        return view('support::admin.tickets.index', [
            'tickets' => TableQuery::paginate(
                $query,
                [
                    'subject' => 'support__tickets.subject',
                    'author' => 'users.username',
                    'category' => 'support__categories.name',
                    'status' => 'support__tickets.status',
                    'updated_at' => 'support__tickets.updated_at',
                ],
                pageName: 'page',
                search: [],
            )->withQueryString(),
            'categories' => Category::query()->orderBy('position')->get(),
            'agents' => app(SupportAccess::class)
                ->agents()
                ->get(['users.id', 'users.username']),
            'authorId' => $authorId,
            'authorName' => $authorId === null ? null : User::query()->whereKey($authorId)->value('username'),
            'counts' => $counts
                ->selectRaw('status, COUNT(*) AS total')
                ->groupBy('status')
                ->pluck('total', 'status'),
        ]);
    }

    public function categories(): View
    {
        $categories = TableQuery::paginate(
            Category::query()->orderBy('position'),
            ['name' => 'name', 'locale' => 'locale', 'access' => 'access', 'enabled' => 'enabled'],
            pageName: 'page',
            search: ['name'],
        );

        return view('support::admin.categories.index', [
            'categories' => $categories,
            'roles' => Role::query()->orderByDesc('position')->get(),
            'categoryRoles' => CategoryRole::query()
                ->whereIn('category_id', $categories->pluck('id'))
                ->get()
                ->groupBy('category_id'),
        ]);
    }

    public function saveCategory(Request $request, ?int $id = null): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'locale' => ['required', Rule::in(array_keys(config('kraft.locales')))],
            'position' => 'required|integer|between:0,10000',
            'icon' => 'required|regex:/^[a-z][a-z0-9-]{1,59}$/D',
            'access' => ['required', Rule::in(['members', 'roles'])],
            'roles' => 'nullable|array|max:100',
            'roles.*' => 'required|integer|distinct|exists:roles,id',
            'introduction' => 'nullable|string|max:10000',
            'structured_form_only' => 'boolean',
            'first_response_minutes' => 'nullable|integer|between:1,525600',
            'resolution_minutes' => 'nullable|integer|between:1,525600',
            'auto_close_hours' => 'nullable|integer|between:1,8760',
            'auto_close_unanswered' => 'boolean',
            'enabled' => 'boolean',
            'replace_legacy_permission' => 'boolean',
        ]);
        $roles = $data['roles'] ?? [];
        unset($data['roles'], $data['replace_legacy_permission']);
        if ($data['access'] === 'roles' && !$roles) {
            throw ValidationException::withMessages([
                'roles' => __('support::admin.categories.choose_roles'),
            ]);
        }
        $data['introduction'] = Html::clean($data['introduction'] ?? '');
        $data['structured_form_only'] = $request->boolean('structured_form_only');
        $data['enabled'] = $request->boolean('enabled');
        DB::transaction(function () use ($data, $roles, $request, $id) {
            if ($id) {
                if (Category::query()->where('id', $id)->lockForUpdate()->first() === null) {
                    abort(404);
                }
                Category::query()
                    ->where('id', $id)
                    ->update($data + ($request->boolean('replace_legacy_permission') ? ['permission' => null] : []));
            } else {
                $id = Category::query()->insertGetId($data + ['permission' => null]);
            }
            Category::query()->findOrFail($id)->roles()->sync($roles);
        });

        return back()->with('status', __('support::messages.tickets.saved'));
    }

    public function fields(): View
    {
        return view('support::admin.fields.index', [
            'categories' => Category::query()->orderBy('position')->get(),
            'fields' => TableQuery::paginate(
                Field::query()
                    ->leftJoin('support__categories', 'support__categories.id', '=', 'support__fields.category_id')
                    ->select('support__fields.*')
                    ->orderBy('support__fields.category_id')
                    ->orderBy('support__fields.position'),
                [
                    'label' => 'support__fields.label',
                    'category' => 'support__categories.name',
                    'type' => 'support__fields.type',
                ],
                pageName: 'page',
                search: ['support__fields.label', 'support__categories.name'],
            ),
        ]);
    }

    public function templates(): View
    {
        return view('support::admin.templates.index', [
            'templates' => TableQuery::paginate(
                ReplyTemplate::query()->orderBy('position'),
                ['title' => 'title', 'kind' => 'kind', 'locale' => 'locale', 'enabled' => 'enabled'],
                pageName: 'page',
                search: ['title'],
            ),
            'categories' => Category::query()->orderBy('position')->get(),
        ]);
    }

    public function saveTemplate(Request $request, ?int $id = null): RedirectResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:120',
            'locale' => ['required', Rule::in(array_keys(config('kraft.locales')))],
            'category_id' => 'nullable|integer|exists:support__categories,id',
            'kind' => ['required', Rule::in(['reply', 'opening'])],
            'body' => 'required|string|max:30000',
            'enabled' => 'boolean',
            'position' => 'required|integer|between:0,10000',
        ]);
        $data['body'] = Html::clean($data['body']);
        $data['enabled'] = $request->boolean('enabled');
        $data['updated_at'] = now();
        if ($id) {
            if (!ReplyTemplate::query()->where('id', $id)->exists()) {
                abort(404);
            }
            ReplyTemplate::query()->where('id', $id)->update($data);
        } else {
            ReplyTemplate::query()->insert($data + ['created_at' => now()]);
        }

        return back()->with('status', __('support::messages.tickets.saved'));
    }

    public function deleteTemplate(int $id): RedirectResponse
    {
        if (!ReplyTemplate::query()->where('id', $id)->exists()) {
            abort(404);
        }
        ReplyTemplate::query()->where('id', $id)->delete();

        return back()->with('status', __('support::messages.tickets.saved'));
    }

    public function settings(): View
    {
        return view('support::admin.settings.edit', [
            'deliveries' => TableQuery::paginate(
                app(Notifications::class)->deliveries('support')->latest('created_at'),
                ['resource' => 'resource', 'type' => 'type', 'channel' => 'channel', 'status' => 'status'],
                pageName: 'page',
                search: [],
            )->through(function ($delivery) {
                $delivery->ticket_id = (int) $delivery->resource;
                $delivery->event = Str::after($delivery->type, 'support.ticket.');

                return $delivery;
            }),
            'webhookConfigured' => app(Secrets::class)->get('support.discord.webhook') !== '',
        ]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'cooldown_seconds' => 'required|integer|between:0,86400',
            'max_open_tickets' => 'required|integer|between:1,100',
            'members_can_reopen' => 'boolean',
            'introduction' => 'nullable|string|max:10000',
            'native' => 'boolean',
            'email' => 'boolean',
            'discord' => 'boolean',
            'webhook_url' => [
                'nullable',
                'string',
                'max:255',
                function ($attribute, $value, $fail) {
                    if ($value && !WebhookClient::validDiscordUrl($value)) {
                        $fail(__('support::admin.notifications.invalid_webhook'));
                    }
                },
            ],
            'remove_webhook' => 'boolean',
        ]);
        $settings = app(Settings::class);
        $wasDiscordEnabled = (bool) $settings->get('support.discord.enabled', false);
        $discordEnabled = $request->boolean('discord');
        $previousUrl = app(Secrets::class)->get('support.discord.webhook');
        $target = $request->boolean('remove_webhook') ? '' : $data['webhook_url'] ?? $previousUrl;
        if ($discordEnabled && !WebhookClient::validDiscordUrl($target)) {
            throw ValidationException::withMessages([
                'webhook_url' => __('support::admin.notifications.invalid_webhook'),
            ]);
        }
        if ($target !== '' && $target !== $previousUrl) {
            app(SensitiveAction::class)->confirm($request);
        }
        $saveWebhook = $request->filled('webhook_url') || $request->boolean('remove_webhook');
        DB::transaction(function () use (
            $request,
            $data,
            $settings,
            $target,
            $saveWebhook,
            $wasDiscordEnabled,
            $discordEnabled,
        ): void {
            foreach (['cooldown_seconds', 'max_open_tickets'] as $key) {
                $settings->set('support.'.$key, (int) $data[$key]);
            }
            if (isset($data['members_can_reopen'])) {
                $settings->set('support.members_can_reopen', $request->boolean('members_can_reopen'));
            }
            $settings->set('support.introduction', Html::clean($data['introduction'] ?? ''));
            foreach (['native', 'email'] as $key) {
                $settings->set('support.notifications.'.$key, $request->boolean($key));
            }
            $settings->set('support.discord.enabled', $discordEnabled);
            if ($saveWebhook) {
                app(Secrets::class)->set('support.discord.webhook', $target);
            }
            if ($saveWebhook || $wasDiscordEnabled !== $discordEnabled) {
                $settings->set('support.discord.configuration_id', (string) Str::uuid());
            }
        });

        return back()->with('status', __('support::messages.tickets.saved'));
    }

    public function retryDelivery(Request $request, string $id): RedirectResponse
    {
        $request->validate(['confirmed' => 'accepted']);
        app(Notifications::class)->retry($id, 'support');

        return back()->with('status', __('support::messages.tickets.saved'));
    }

    public function saveField(Request $request, ?int $id = null): RedirectResponse
    {
        $data = $request->validate([
            'category_id' => 'required|integer|exists:support__categories,id',
            'key' => [
                'required',
                'regex:/^[a-z][a-z0-9_]{0,59}$/',
                Rule::unique('support__fields')->where('category_id', $request->integer('category_id'))->ignore($id),
            ],
            'label' => 'required|string|max:120',
            'help_text' => 'nullable|string|max:500',
            'type' => ['required', Rule::in(Field::TYPES)],
            'options' => 'nullable|string|max:5000',
            'required' => 'boolean',
            'staff_only' => 'boolean',
            'enabled' => 'boolean',
            'position' => 'required|integer|between:0,10000',
        ]);
        $options = array_values(
            array_unique(
                array_filter(
                    array_map('trim', preg_split('/\r\n|\r|\n/', $data['options'] ?? '')),
                    fn ($value) => $value !== '',
                ),
            ),
        );
        if (
            $data['type'] === 'select' &&
            (!$options || count($options) > 30 || !collect($options)->every(fn ($option) => mb_strlen($option) <= 120))
        ) {
            throw ValidationException::withMessages(['options' => __('support::admin.forms.invalid_options')]);
        }
        $data['options'] = $data['type'] === 'select' ? $options : [];
        foreach (['required', 'staff_only', 'enabled'] as $flag) {
            $data[$flag] = $request->boolean($flag);
        }
        DB::transaction(function () use ($id, $data): void {
            if (!$id) {
                Field::query()->create($data);

                return;
            }
            $field = Field::query()->lockForUpdate()->find($id);
            if (!$field) {
                abort(404);
            }
            $field->update($data);
            if ($field->staff_only) {
                $field->fieldValues()->update(['staff_only' => true]);
            }
        }, 3);

        return back()->with('status', __('support::messages.tickets.saved'));
    }

    public function deleteField(int $id): RedirectResponse
    {
        Field::query()->where('id', $id)->delete();

        return back();
    }

    public function deleteCategory(int $id): RedirectResponse
    {
        if (Ticket::query()->where('category_id', $id)->exists()) {
            throw new HttpException(422, __('support::messages.tickets.category_used'));
        }
        Category::query()->where('id', $id)->delete();

        return back();
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $request->validate(['confirmed' => 'accepted']);
        app(SupportTrash::class)->trashTicket($request->user(), $id);

        return redirect()
            ->route('plugins.support.admin.tickets.index')
            ->with('status', __('support::messages.tickets.saved'));
    }
}
