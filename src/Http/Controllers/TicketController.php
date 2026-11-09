<?php

namespace KraftInk\Plugins\Support\Http\Controllers;

use App\Kraft\Html;
use App\Kraft\Settings;
use App\Kraft\System\TableQuery;
use App\Models\Accounts\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use KraftInk\Plugins\Support\Models\Field;
use KraftInk\Plugins\Support\Models\FieldValue;
use KraftInk\Plugins\Support\Models\Message;
use KraftInk\Plugins\Support\Models\ReplyTemplate;
use KraftInk\Plugins\Support\Models\Ticket;
use KraftInk\Plugins\Support\Services\SupportAccess;
use KraftInk\Plugins\Support\Services\SupportDrafts;
use KraftInk\Plugins\Support\Services\SupportFiles;
use KraftInk\Plugins\Support\Services\SupportNotifications;
use KraftInk\Plugins\Support\Services\SupportWorkflow;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use KraftInk\Plugins\Support\Models\TicketEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TicketController
{
    public function index(Request $request): View
    {
        $query = Ticket::query()->whereNull('deleted_at')->orderByDesc('updated_at');
        if (!$request->user()->hasPermission('support.manage')) {
            $query->where('user_id', $request->user()->id);
        } elseif ($request->input('assigned') === 'me') {
            $query->whereBelongsTo($request->user(), 'assignee');
        }
        $request->validate(['q' => 'nullable|string|max:200']);
        if ($request->filled('q')) {
            $search = trim($request->input('q'));
            $query->where(function ($query) use ($request, $search) {
                TableQuery::whereContains($query, ['subject'], $search);
                $query->orWhereExists(function ($messages) use ($request, $search) {
                    $messages
                        ->selectRaw('1')
                        ->from('support__messages')
                        ->whereColumn('support__messages.ticket_id', 'support__tickets.id')
                        ->whereNull('support__messages.deleted_at')
                        ->when(
                            !$request->user()->hasPermission('support.manage'),
                            fn ($query) => $query->where('internal', false),
                        );
                    TableQuery::whereContains($messages, ['body'], $search);
                });
            });
        }
        if (in_array($request->input('status'), ['open', 'answered', 'closed'], true)) {
            $query->where('status', $request->input('status'));
        }
        $categories = $this->categories($request);
        $selectedCategory =
            $categories->firstWhere('id', (int) $request->old('category_id', $request->input('category'))) ??
            $categories->first();

        return view('support::default.tickets.index', [
            'tickets' => $query->paginate(25)->withQueryString(),
            'categories' => $categories,
            'selectedCategory' => $selectedCategory,
            'fields' => $selectedCategory
                ? $selectedCategory->fields()->forRequesters()->orderBy('position')->get()
                : collect(),
            'draft' => $selectedCategory
                ? app(SupportDrafts::class)->get($request->user()->id, 'category_'.$selectedCategory->id)
                : [],
            'templates' => $selectedCategory && !$selectedCategory->structured_form_only
                ? ReplyTemplate::query()
                    ->availableFor($selectedCategory->id, 'opening', app()->getLocale())
                    ->orderBy('position')
                    ->get()
                : collect(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $categoryId = $request->validate([
            'category_id' => ['required', 'integer', 'exists:support__categories,id'],
        ])['category_id'];
        $category = $this->categories($request)->firstWhere('id', (int) $categoryId);
        if (!$category) {
            throw new HttpException(403);
        }
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:180'],
            'body' => [Rule::excludeIf($category->structured_form_only), 'required', 'string', 'min:10', 'max:30000'],
            'attachment' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp,pdf,txt,log', 'max:5120'],
        ]);
        $fields = $category->fields()->forRequesters()->orderBy('position')->get();
        $values = $this->validateFields($request, $fields);
        $id = DB::transaction(function () use ($data, $request, $fields, $values, $category) {
            app(SupportWorkflow::class)->limits($request->user());
            $id = Ticket::query()->insertGetId(
                [
                    'user_id' => $request->user()->id,
                    'category_id' => $category->id,
                    'subject' => $data['subject'],
                    'status' => 'open',
                    'priority' => 'normal',
                    'created_at' => now(),
                    'updated_at' => now(),
                ] + app(SupportWorkflow::class)->dates($category),
            );
            $this->message($request, $id, false, 'category_'.$category->id, $data['body'] ?? null);
            $this->saveValues($id, $fields, $values);
            $this->event($id, $request->user()->id, 'created');
            app(SupportNotifications::class)->enqueue(Ticket::query()->find($id), 'created', $request->user()->id);
            app(SupportDrafts::class)->forget($request->user()->id, 'category_'.$category->id);

            return $id;
        });

        return redirect()
            ->route('plugins.support.public.tickets.show', $id)
            ->with('status', __('support::messages.tickets.created'));
    }

    public function show(Request $request, int $id): View
    {
        $ticket = $this->ticket($request, $id);
        $access = app(SupportAccess::class);
        $staff = $request->user()->hasPermission('support.manage');
        $query = $ticket->messages()->visibleTo($request->user())->with(['user', 'attachment'])->orderBy('id');

        $firstMessageId = (int) $ticket->messages()->min('id');
        $messages = $query->reorder('support__messages.id', 'desc')->paginate(50)->withQueryString();
        foreach ($messages as $message) {
            /** @var Message $message */
            $message->username = $message->user?->username;
            $message->canManage = $access->canManageMessage($request->user(), $ticket, $message);
            $message->canDelete =
                $message->canManage && !$message->deleted_at && (int) $message->id !== $firstMessageId;
            $message->canDownloadAttachment = !$message->deleted_at && (bool) $message->attachment;
        }
        $agents = $staff ? $this->agents() : collect();
        $events = $staff
            ? $ticket->events()->with('actor')->latest('support__ticket_events.id')->limit(50)->get()
            : collect();
        foreach ($events as $event) {
            /** @var TicketEvent $event */
            $event->actor_username = $event->actor?->username;
            $event->labelKey = in_array($event->event, ['replied', 'internal_note', 'auto_closed', 'escalated'])
                ? 'support::admin.history.event_'.$event->event
                : 'support::admin.forms.event_'.$event->event;
        }

        $ticketStatuses = array_values(array_unique([$ticket->status, 'open', 'closed']));
        if ($staff) {
            $ticketStatuses = ['open', 'answered', 'closed'];
        } elseif ($ticket->status === 'closed' && !app(Settings::class)->get('support.members_can_reopen', true)) {
            $ticketStatuses = ['closed'];
        }

        return view(
            $request->routeIs('plugins.support.admin.tickets.show')
                ? 'support::admin.tickets.show'
                : 'support::default.tickets.show',
            [
                'ticket' => $ticket,
                'firstMessageId' => $firstMessageId,
                'messages' => $messages,
                'staff' => $staff,
                'canReply' => $access->canReply($ticket),
                'canAssign' => $staff && $request->user()->hasPermission('support.assign'),
                'ticketStatuses' => $ticketStatuses,
                'agents' => $agents,
                'assignedAgentUsername' => $agents->firstWhere('id', $ticket->assigned_to)?->username,
                'fieldValues' => FieldValue::query()
                    ->where('ticket_id', $id)
                    ->when(!$staff, fn ($query) => $query->where('staff_only', false))
                    ->get(),
                'fields' => $staff
                    ? Field::query()
                        ->where('category_id', $ticket->category_id)
                        ->where('enabled', true)
                        ->orderBy('position')
                        ->get()
                    : collect(),
                'events' => $events,
                'templates' => $staff
                    ? ReplyTemplate::query()
                        ->availableFor($ticket->category_id, 'reply', app()->getLocale())
                        ->orderBy('position')
                        ->get()
                    : collect(),
                'draft' => app(SupportDrafts::class)->get($request->user()->id, 'ticket_'.$id),
            ],
        );
    }

    public function reply(Request $request, int $id): RedirectResponse
    {
        $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:30000'],
            'internal' => ['sometimes', 'boolean'],
            'attachment' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp,pdf,txt,log', 'max:5120'],
        ]);
        DB::transaction(function () use ($request, $id) {
            $ticket = $this->ticket($request, $id, true);
            if (!app(SupportAccess::class)->canReply($ticket)) {
                throw new HttpException(422, __('support::messages.tickets.closed'));
            }
            $staff = $request->user()->hasPermission('support.manage');
            $internal = $staff && $request->boolean('internal');
            $this->message($request, $id, $internal, 'ticket_'.$id, $request->input('body'));
            if (!$internal) {
                $dates = $staff
                    ? ['last_staff_reply_at' => now(), 'first_staff_reply_at' => $ticket->first_staff_reply_at ?? now()]
                    : [];
                Ticket::query()
                    ->where('id', $id)
                    ->update(['status' => $staff ? 'answered' : 'open', 'updated_at' => now()] + $dates);
                app(SupportNotifications::class)->enqueue($ticket, 'replied', $request->user()->id);
            }
            $this->event($id, $request->user()->id, $internal ? 'internal_note' : 'replied');
            app(SupportDrafts::class)->forget($request->user()->id, 'ticket_'.$id);
        });

        return back()->with('status', __('support::messages.tickets.sent'));
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['open', 'answered', 'closed'])],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])],
        ]);
        $user = $request->user();
        $staff = $user->hasPermission('support.manage');
        $openingAsMember = !$staff && $data['status'] === 'open';
        DB::transaction(function () use ($data, $request, $id, $user, $staff, $openingAsMember) {
            if ($openingAsMember) {
                User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            }
            $ticket = $this->ticket($request, $id, true);
            if (!$staff) {
                if (!in_array($data['status'], ['open', 'closed', $ticket->status], true)) {
                    throw new HttpException(403);
                }
                unset($data['priority']);
            }
            if ($openingAsMember && $ticket->status === 'closed') {
                if (!app(Settings::class)->get('support.members_can_reopen', true)) {
                    throw ValidationException::withMessages(['status' => __('support::messages.tickets.reopen_disabled')]);
                }
                app(SupportWorkflow::class)->ensureOpenTicketCapacity($user, 'status');
            }
            $dates = ['updated_at' => now()];
            if ($data['status'] !== $ticket->status) {
                $dates['closed_at'] = $data['status'] === 'closed' ? now() : null;
                if ($ticket->status === 'closed') {
                    $dates['reopened_at'] = now();
                }
            }
            Ticket::query()->where('id', $ticket->id)->update($data + $dates);
            $this->event($id, $user->id, 'updated', $data, $ticket->status);
            $notification = match (true) {
                $data['status'] === $ticket->status => null,
                $data['status'] === 'closed' => 'closed',
                $ticket->status === 'closed' => 'reopened',
                default => null,
            };
            if ($notification !== null) {
                app(SupportNotifications::class)->enqueue($ticket, $notification, $user->id);
            }
        });

        return back()->with('status', __('support::messages.tickets.saved'));
    }

    public function assign(Request $request, int $id): RedirectResponse
    {
        $request->validate(['assigned_to' => 'nullable|integer|exists:users,id']);
        $this->ticket($request, $id);
        $agent = $request->filled('assigned_to') ? User::query()->findOrFail($request->input('assigned_to')) : null;
        if ($agent && !app(SupportAccess::class)->agents()->where('users.id', $agent->id)->exists()) {
            throw new HttpException(422, __('support::admin.forms.invalid_agent'));
        }
        DB::transaction(function () use ($request, $id, $agent) {
            $ticket = $this->ticket($request, $id, true);
            $ticket->assignee()->associate($agent)->save();
            $this->event($id, $request->user()->id, 'assigned', ['agent' => $agent?->username]);
            app(SupportNotifications::class)->enqueue($ticket, 'assigned', $request->user()->id);
        });

        return back()->with('status', __('support::messages.tickets.saved'));
    }

    public function updateFields(Request $request, int $id): RedirectResponse
    {
        $ticket = $this->ticket($request, $id);
        $fields = Field::query()->where('category_id', $ticket->category_id)->where('enabled', true)->get();
        $values = $this->validateFields($request, $fields);
        DB::transaction(function () use ($fields, $values, $id, $request) {
            $this->ticket($request, $id, true);
            $this->saveValues($id, $fields, $values);
            $this->event($id, $request->user()->id, 'fields_updated');
        });

        return back()->with('status', __('support::messages.tickets.saved'));
    }

    private function agents()
    {
        return app(SupportAccess::class)
            ->agents()
            ->get(['users.id', 'users.username']);
    }

    private function validateFields(Request $request, $fields): array
    {
        $rules = [];
        $labels = [];
        foreach ($fields as $field) {
            $rule = [$field->required ? 'required' : 'nullable'];
            if ($field->type === 'checkbox') {
                $rule[] = $field->required ? 'accepted' : 'boolean';
            } elseif ($field->type === 'number') {
                $rule[] = 'numeric';
                $rule[] = 'between:-999999999,999999999';
            } elseif ($field->type === 'email') {
                $rule[] = 'email:rfc';
                $rule[] = 'max:254';
            } else {
                $rule[] = 'string';
                $rule[] = 'max:'.($field->type === 'textarea' ? 5000 : 500);
                if ($field->type === 'select') {
                    $rule[] = Rule::in($field->options);
                }
            }
            $key = 'custom_fields.'.$field->id;
            $rules[$key] = $rule;
            $labels[$key] = $field->label;
        }
        $data = $request->validate($rules, [], $labels);

        return $data['custom_fields'] ?? [];
    }

    private function saveValues(int $ticket, $fields, array $values): void
    {
        foreach ($fields as $field) {
            FieldValue::query()->updateOrInsert(
                ['ticket_id' => $ticket, 'field_id' => $field->id],
                [
                    'field_label' => $field->label,
                    'staff_only' => $field->staff_only,
                    'value' => isset($values[$field->id]) ? (string) $values[$field->id] : null,
                ],
            );
        }
    }

    private function event(
        int $ticket,
        int $actor,
        string $event,
        array $data = [],
        ?string $previousStatus = null,
    ): void {
        app(SupportWorkflow::class)->event($ticket, $actor, $event, $data, $previousStatus);
    }

    private function ticket(Request $request, int $id, bool $lock = false): object
    {
        return app(SupportAccess::class)->ticket($request->user(), $id, $lock);
    }

    private function categories(Request $request)
    {
        return app(SupportAccess::class)->categories($request->user());
    }

    private function message(Request $request, int $ticketId, bool $internal, string $context, ?string $content): void
    {
        $body = Html::clean($content);
        $text = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (
            $content !== null &&
            !preg_match('/[^\s\p{Z}\x{200B}\x{FEFF}]/u', $text) &&
            !preg_match('/<img\b/i', $body)
        ) {
            throw ValidationException::withMessages([
                'body' => __('validation.required', ['attribute' => __('support::messages.tickets.message')]),
            ]);
        }
        $file = $request->hasFile('attachment')
            ? app(SupportFiles::class)->store($request->file('attachment'), $request->user(), $context)
            : null;
        $id = Message::query()->insertGetId([
            'ticket_id' => $ticketId,
            'user_id' => $request->user()->id,
            'body' => $body,
            'internal' => $internal,
            'created_at' => now(),
        ]);
        app(SupportFiles::class)->bind($id, $request->user(), $context, $body, $file);
    }
}
