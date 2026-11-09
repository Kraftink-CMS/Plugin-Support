<?php

namespace KraftInk\Plugins\Support\Services;

use App\Kraft\Settings;
use App\Models\Accounts\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use KraftInk\Plugins\Support\Models\Category;
use KraftInk\Plugins\Support\Models\Ticket;
use KraftInk\Plugins\Support\Models\TicketEvent;
use App\Kraft\Events\PluginEvents;

class SupportWorkflow
{
    public function limits(User $user): void
    {
        User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
        if ($user->hasPermission('support.manage')) {
            return;
        }
        $settings = app(Settings::class);
        $cooldown = max(0, min(86400, (int) $settings->get('support.cooldown_seconds', 60)));
        if (
            $cooldown &&
            Ticket::query()
                ->where('user_id', $user->id)
                ->where('created_at', '>', now()->subSeconds($cooldown))
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'subject' => __('support::admin.settings.cooldown', ['seconds' => $cooldown]),
            ]);
        }

        $this->ensureOpenTicketCapacity($user, 'subject');
    }

    public function ensureOpenTicketCapacity(User $user, string $errorField): void
    {
        $maximum = max(1, min(100, (int) app(Settings::class)->get('support.max_open_tickets', 5)));
        if (
            Ticket::query()
                ->where('user_id', $user->id)
                ->whereNull('deleted_at')
                ->where('status', '!=', 'closed')
                ->count() >= $maximum
        ) {
            throw ValidationException::withMessages([
                $errorField => __('support::admin.settings.too_many_open', ['count' => $maximum]),
            ]);
        }
    }

    public function dates(object $category): array
    {
        return [
            'first_response_due_at' => $category->first_response_minutes
                ? now()->addMinutes($category->first_response_minutes)
                : null,
            'resolution_due_at' => $category->resolution_minutes
                ? now()->addMinutes($category->resolution_minutes)
                : null,
        ];
    }

    public function event(
        int $ticket,
        ?int $actor,
        string $event,
        array $data = [],
        ?string $previousStatus = null,
    ): void {
        $id = TicketEvent::query()->insertGetId([
            'ticket_id' => $ticket,
            'actor_id' => $actor,
            'event' => $event,
            'data' => json_encode($data, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
        $name = match ($event) {
            'created' => 'support.ticket.created',
            'replied' => 'support.ticket.replied',
            'auto_closed' => 'support.ticket.resolved',
            'updated' => ($data['status'] ?? null) === 'closed' && $previousStatus !== 'closed'
                ? 'support.ticket.resolved'
                : null,
            default => null,
        };
        if ($name !== null) {
            $record = Ticket::query()->find($ticket);
            app(PluginEvents::class)->publish(
                $name,
                [
                    'ticket_id' => $ticket,
                    'ticket_event_id' => $id,
                    'user_id' => $record->user_id,
                    'actor_id' => $actor,
                    'category_id' => $record->category_id,
                    'automatic' => $event === 'auto_closed',
                ],
                $name.':'.$id,
            );
        }
    }

    public function run(int $limit = 100): int
    {
        $settings = app(Settings::class);
        $cursor = max(0, (int) $settings->get('support.workflow_cursor', 0));
        $batch = max(1, min(1000, $limit));
        $ids = Ticket::query()
            ->whereNull('deleted_at')
            ->where('status', '!=', 'closed')
            ->where('id', '>', $cursor)
            ->orderBy('id')
            ->limit($batch)
            ->pluck('id');
        $processed = 0;
        $started = microtime(true);
        foreach ($ids as $id) {
            if ($processed >= max(1, min(1000, $limit)) || microtime(true) - $started > 20) {
                break;
            }
            DB::transaction(function () use ($id, &$processed) {
                $ticket = Ticket::query()->where('id', $id)->lockForUpdate()->first();
                if (!$ticket || $ticket->deleted_at || $ticket->status === 'closed') {
                    return;
                }
                $category = Category::query()->find($ticket->category_id);
                $lastActivity = null;
                if ($category?->auto_close_hours) {
                    if ($ticket->status === 'answered') {
                        $lastActivity = $ticket->last_staff_reply_at;
                    } elseif ($category->auto_close_unanswered) {
                        $lastActivity = $ticket->messages()
                            ->where('internal', false)
                            ->whereNull('deleted_at')
                            ->latest('created_at')
                            ->value('created_at');
                    }
                }
                if ($lastActivity && $ticket->reopened_at?->greaterThan($lastActivity)) {
                    $lastActivity = $ticket->reopened_at;
                }
                if ($lastActivity && now()->subHours($category->auto_close_hours)->greaterThanOrEqualTo($lastActivity)) {
                    Ticket::query()
                        ->where('id', $id)
                        ->update(['status' => 'closed', 'closed_at' => now(), 'updated_at' => now()]);
                    $this->event($id, null, 'auto_closed');
                    app(SupportNotifications::class)->enqueue($ticket, 'closed');
                    $processed++;

                    return;
                }
                $overdue =
                    (!$ticket->first_staff_reply_at &&
                        $ticket->first_response_due_at &&
                        now()->greaterThan($ticket->first_response_due_at)) ||
                    ($ticket->resolution_due_at && now()->greaterThan($ticket->resolution_due_at));
                if ($overdue && !$ticket->escalated_at) {
                    Ticket::query()
                        ->where('id', $id)
                        ->update(['escalated_at' => now(), 'updated_at' => now()]);
                    $this->event($id, null, 'escalated');
                    app(SupportNotifications::class)->enqueue($ticket, 'escalated');
                    $processed++;
                }
            }, 3);
            $cursor = $id;
        }
        $settings->set(
            'support.workflow_cursor',
            $ids->isEmpty() || ($ids->count() < $batch && $cursor === $ids->last()) ? 0 : $cursor,
        );

        return $processed;
    }
}
