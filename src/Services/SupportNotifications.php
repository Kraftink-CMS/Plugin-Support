<?php

namespace KraftInk\Plugins\Support\Services;

use App\Kraft\Notifications\NotificationRegistry;
use App\Kraft\Notifications\Notifications;
use App\Kraft\Secrets;
use App\Kraft\Settings;
use App\Models\Accounts\User;
use KraftInk\Plugins\Support\Models\Message;
use KraftInk\Plugins\Support\Models\Ticket;
use KraftInk\Plugins\Support\Models\TicketEvent;

class SupportNotifications
{
    public function enqueue(object $ticket, string $event, ?int $actor = null): void
    {
        $settings = app(Settings::class);
        $recipients = collect([$ticket->user_id]);
        if ($ticket->assigned_to) {
            $recipients->push($ticket->assigned_to);
        } else {
            foreach (app(SupportAccess::class)->agents()->select('users.id')->cursor() as $agent) {
                /** @var User $agent */
                $recipients->push($agent->id);
            }
        }
        $channels = array_values(
            array_filter(
                ['native', 'email'],
                fn (string $channel) => $settings->get('support.notifications.'.$channel, $channel === 'native'),
            ),
        );
        $history = TicketEvent::query()->where('ticket_id', $ticket->id)->max('id') ?? 0;
        $message = Message::query()->where('ticket_id', $ticket->id)->max('id') ?? 0;
        $key = $ticket->id.':'.$event.':'.$history.':'.$message;
        $data = ['ticket_id' => (int) $ticket->id];
        $notifications = app(Notifications::class);
        $recipients = $recipients->unique()->filter(fn ($id) => $id && $id !== $actor);
        $users = User::query()->whereKey($recipients)->get()->keyBy('id');
        foreach ($recipients as $id) {
            $user = $users->get($id);
            if ($user) {
                /** @var User $user */
                $notifications->notify('support.ticket.'.$event, $user, $data, $key, $channels, (string) $ticket->id);
            }
        }
        if ($settings->get('support.discord.enabled', false) && $settings->get('support.discord.configuration_id')) {
            $notifications->webhook(
                'support.ticket.'.$event,
                $data,
                $key,
                $settings->get('support.discord.configuration_id'),
                (string) $ticket->id,
            );
        }
    }

    public static function register(NotificationRegistry $registry): void
    {
        foreach (['created', 'replied', 'closed', 'reopened', 'assigned', 'escalated'] as $event) {
            $registry->register(
                'support',
                'support.ticket.'.$event,
                'support::messages.notifications.notice_'.$event,
                fn (array $data, ?User $user): array => [
                    'title' => __('support::messages.notifications.notice_title'),
                    'body' => '<p>'.e(__('support::messages.notifications.notice_'.$event)).'</p>',
                    'url' => route('plugins.support.public.tickets.show', $data['ticket_id']),
                ],
                function (?User $user, array $data, ?string $channel = null): bool {
                    $ticket = Ticket::query()->whereNull('deleted_at')->find($data['ticket_id']);
                    if (!$ticket) {
                        return false;
                    }
                    if ($user === null) {
                        return (bool) app(Settings::class)->get('support.discord.enabled', false);
                    }
                    if (
                        $channel !== null &&
                        !app(Settings::class)->get('support.notifications.'.$channel, $channel === 'native')
                    ) {
                        return false;
                    }

                    return app(SupportAccess::class)->canViewTicket($user, $ticket);
                },
                defaults: ['native' => true, 'email' => true],
                webhook: function (): ?array {
                    $settings = app(Settings::class);
                    if (!$settings->get('support.discord.enabled', false)) {
                        return null;
                    }

                    return [
                        'url' => app(Secrets::class)->get('support.discord.webhook'),
                        'configuration_id' => $settings->get('support.discord.configuration_id'),
                    ];
                },
            );
        }
    }
}
