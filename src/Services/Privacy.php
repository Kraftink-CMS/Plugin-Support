<?php

namespace KraftInk\Plugins\Support\Services;

use App\Events\AccountDeleting;
use App\Events\ExportingPersonalData;
use App\Kraft\Extensions\Contracts\PrivacyHandler;
use App\Models\Notifications\NotificationDelivery;
use Illuminate\Support\Facades\Route;
use KraftInk\Plugins\Support\Models\Draft;
use KraftInk\Plugins\Support\Models\FieldValue;
use KraftInk\Plugins\Support\Models\FileAttachment;
use KraftInk\Plugins\Support\Models\Message;
use KraftInk\Plugins\Support\Models\MessageRevision;
use KraftInk\Plugins\Support\Models\Ticket;
use KraftInk\Plugins\Support\Models\TicketEvent;
use RuntimeException;
use App\Kraft\Notifications\Notifications;

class Privacy implements PrivacyHandler
{
    public function export(ExportingPersonalData $event): void
    {
        $id = $event->user->id;
        $tickets = Ticket::query()->where('user_id', $id)->select('id');
        $messages = Message::query()->whereIn('ticket_id', clone $tickets)->where('internal', false);
        if ($event->user->hasPermission('support.manage')) {
            $messages->orWhere(fn ($query) => $query->where('user_id', $id)->whereNotIn('ticket_id', clone $tickets));
        }
        $event->data['support'] = [
            'tickets' => Ticket::query()
                ->where('user_id', $id)
                ->orderBy('id')
                ->select(['id', 'category_id', 'subject', 'status', 'priority', 'created_at', 'updated_at'])
                ->cursor(),
            'messages' => $messages
                ->orderBy('id')
                ->select(['id', 'ticket_id', 'user_id', 'body', 'internal', 'created_at'])
                ->cursor(),
            'fields' => FieldValue::query()
                ->leftJoin('support__fields', 'support__fields.id', '=', 'support__field_values.field_id')
                ->whereIn('support__field_values.ticket_id', clone $tickets)
                ->where('support__field_values.staff_only', false)
                ->where(
                    fn ($query) => $query->whereNull('support__fields.id')->orWhere('support__fields.staff_only', false),
                )
                ->orderBy('support__field_values.id')
                ->select([
                    'support__field_values.ticket_id',
                    'support__field_values.field_label',
                    'support__field_values.value',
                ])
                ->cursor(),
            'activity' => TicketEvent::query()
                ->where('actor_id', $id)
                ->orderBy('id')
                ->select(['ticket_id', 'event', 'created_at'])
                ->cursor(),
            'drafts' => Draft::query()
                ->where('user_id', $id)
                ->where('expires_at', '>', now())
                ->orderBy('id')
                ->cursor()
                ->map(
                    fn ($draft) => [
                        'context' => $draft->context,
                        'draft' => $draft->payload,
                        'expires_at' => $draft->expires_at,
                    ],
                ),
            'files' => FileAttachment::query()
                ->where(
                    fn ($query) => $query
                        ->whereIn('message_id', (clone $messages)->select('id'))
                        ->orWhere(fn ($staged) => $staged->whereNull('message_id')->where('user_id', $id)),
                )
                ->orderBy('id')
                ->select(['id', 'message_id', 'inline', 'name', 'mime', 'size', 'created_at'])
                ->cursor()
                ->map(function ($file) {
                    $download = $file->message_id && !$file->inline;
                    $route = $download
                        ? 'plugins.support.public.attachments.download'
                        : 'plugins.support.public.files.show';
                    $file->url = Route::has($route) ? route($route, $download ? $file->message_id : $file->id) : null;

                    return $file;
                }),
            'revisions' => MessageRevision::query()
                ->whereIn('message_id', (clone $messages)->select('id'))
                ->orderBy('id')
                ->select(['message_id', 'body', 'created_at'])
                ->cursor(),
        ];
    }

    public function anonymize(AccountDeleting $event): void
    {
        $id = $event->user->id;
        $tickets = Ticket::query()->where('user_id', $id)->select('id');
        if (
            NotificationDelivery::query()
                ->where('owner', 'support')
                ->where('status', 'sending')
                ->where('started_at', '>=', now()->subMinutes(10))
                ->where(fn ($query) => $query->where('user_id', $id)->orWhereIn('resource', clone $tickets))
                ->exists()
        ) {
            throw new RuntimeException(__('support::admin.operations.privacy_busy'));
        }
        $messages = Message::query()->where(
            fn ($query) => $query->where('user_id', $id)->orWhereIn('ticket_id', clone $tickets),
        );
        MessageRevision::query()
            ->whereIn('message_id', (clone $messages)->select('id'))
            ->update(['body' => '', 'anonymized' => true]);
        MessageRevision::query()
            ->where('user_id', $id)
            ->update(['user_id' => null]);
        $ownedFiles = FileAttachment::query()->where(
            fn ($query) => $query->where('user_id', $id)->orWhereIn('message_id', (clone $messages)->select('id')),
        );
        $files = (clone $ownedFiles)->pluck('path');
        $ownedFiles->delete();
        $notifications = app(Notifications::class);
        $notifications->forget('support', user: $id);
        foreach ((clone $tickets)->pluck('id') as $ticket) {
            $notifications->forget('support', (string) $ticket);
        }
        Draft::query()->where('user_id', $id)->delete();
        (clone $messages)->update(['body' => '']);
        Message::query()
            ->where('user_id', $id)
            ->update(['user_id' => null]);
        FieldValue::query()
            ->whereIn('ticket_id', clone $tickets)
            ->update(['value' => null]);
        TicketEvent::query()
            ->where(fn ($query) => $query->where('actor_id', $id)->orWhereIn('ticket_id', clone $tickets))
            ->update(['data' => null]);
        TicketEvent::query()
            ->where('actor_id', $id)
            ->update(['actor_id' => null]);
        Ticket::query()
            ->where('assigned_to', $id)
            ->update(['assigned_to' => null]);
        Ticket::query()
            ->where('user_id', $id)
            ->update([
                'subject' => __('support::messages.privacy.removed_ticket'),
                'status' => 'closed',
                'updated_at' => now(),
            ]);
        $supportFiles = app(SupportFiles::class);
        foreach ($files as $path) {
            $supportFiles->scheduleDeletion($path);
        }
    }
}
