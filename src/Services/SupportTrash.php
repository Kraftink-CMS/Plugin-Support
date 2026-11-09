<?php

namespace KraftInk\Plugins\Support\Services;

use App\Kraft\Audit;
use App\Kraft\Notifications\Notifications;
use App\Kraft\Trash\TrashRegistry;
use App\Kraft\Trash\TrashSource;
use App\Models\Accounts\User;
use Illuminate\Support\Facades\DB;
use KraftInk\Plugins\Support\Models\Draft;
use KraftInk\Plugins\Support\Models\FileAttachment;
use KraftInk\Plugins\Support\Models\Message;
use KraftInk\Plugins\Support\Models\MessageRevision;
use KraftInk\Plugins\Support\Models\Ticket;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SupportTrash
{
    public function trashTicket(User $actor, int $id): void
    {
        if (!$actor->hasPermission('support.manage')) {
            throw new HttpException(403);
        }
        DB::transaction(function () use ($actor, $id): void {
            app(SupportAccess::class)->ticket($actor, $id, true);
            $this->idle($id);
            Ticket::query()
                ->where('id', $id)
                ->update(['deleted_at' => now(), 'updated_at' => now()]);
            app(Notifications::class)->dismiss('support', (string) $id);
            Audit::record('support.ticket_removed', ['ticket_id' => $id], $actor);
        }, 3);
    }

    public function restoreTicket(User $actor, int $id): void
    {
        if (!$actor->hasPermission('support.manage')) {
            throw new HttpException(403);
        }
        DB::transaction(function () use ($actor, $id): void {
            $ticket = Ticket::query()->where('id', $id)->whereNotNull('deleted_at')->lockForUpdate()->first();
            if ($ticket === null) {
                abort(404);
            }
            if (!User::query()->whereKey($ticket->user_id)->whereNull('anonymized_at')->exists()) {
                throw new HttpException(410);
            }
            Ticket::query()
                ->where('id', $id)
                ->update(['deleted_at' => null, 'updated_at' => now()]);
            Audit::record('support.ticket_restored', ['ticket_id' => $id], $actor);
        }, 3);
    }

    public function restoreMessage(User $actor, int $id): void
    {
        if (!$actor->hasPermission('support.manage')) {
            throw new HttpException(403);
        }
        DB::transaction(function () use ($actor, $id): void {
            [$message, $ticket] = $this->message($actor, $id);
            if (
                !(
                    $message->user_id &&
                    trim($message->body) !== '' &&
                    User::query()->whereKey($message->user_id)->whereNull('anonymized_at')->exists()
                )
            ) {
                throw new HttpException(410);
            }
            app(SupportFiles::class)->bind($id, $actor, 'ticket_'.$ticket->id, $message->body);
            MessageRevision::query()->insert([
                'message_id' => $id,
                'user_id' => $actor->id,
                'body' => $message->body,
                'deleted_at' => $message->deleted_at,
                'created_at' => now(),
            ]);
            Message::query()
                ->where('id', $id)
                ->update(['deleted_at' => null, 'updated_at' => now()]);
            Audit::record('support.message_restored', ['ticket_id' => $ticket->id, 'message_id' => $id], $actor);
        }, 3);
    }

    public function purgeTicket(User $actor, int $id): void
    {
        if (!($actor->hasPermission('support.manage') && $actor->hasPermission('support.purge'))) {
            throw new HttpException(403);
        }
        DB::transaction(function () use ($actor, $id): void {
            $ticket = Ticket::query()->where('id', $id)->whereNotNull('deleted_at')->lockForUpdate()->first();
            if ($ticket === null) {
                abort(404);
            }
            $this->idle($id);
            $messages = Message::query()->where('ticket_id', $id)->orderBy('id')->lockForUpdate()->pluck('id');
            $files = FileAttachment::query()->where(
                fn ($query) => $query->whereIn('message_id', $messages)->orWhere('context', 'ticket_'.$id),
            );
            $paths = (clone $files)
                ->lockForUpdate()
                ->pluck('path');
            $files->delete();
            Draft::query()
                ->where('context', 'ticket_'.$id)
                ->delete();
            app(Notifications::class)->forget('support', (string) $id);
            Ticket::query()->where('id', $id)->delete();
            $this->scheduleFiles($paths);
            Audit::record('support.ticket_purged', ['ticket_id' => $id], $actor);
        }, 3);
    }

    public function purgeMessage(User $actor, int $id): void
    {
        if (!($actor->hasPermission('support.manage') && $actor->hasPermission('support.purge'))) {
            throw new HttpException(403);
        }
        DB::transaction(function () use ($actor, $id): void {
            [$message, $ticket] = $this->message($actor, $id);
            $this->idle($ticket->id);
            $paths = FileAttachment::query()->where('message_id', $id)->lockForUpdate()->pluck('path');
            Message::query()->where('id', $id)->delete();
            $this->scheduleFiles($paths);
            Audit::record('support.message_purged', ['ticket_id' => $ticket->id, 'message_id' => $id], $actor);
        }, 3);
    }

    private function message(User $actor, int $id): array
    {
        $message = Message::query()->where('id', $id)->first();
        if ($message === null) {
            abort(404);
        }
        $ticket = app(SupportAccess::class)->ticket($actor, $message->ticket_id, true);
        $message = Message::query()->where('id', $id)->whereNotNull('deleted_at')->lockForUpdate()->first();
        if (!($message && $message->ticket_id === $ticket->id)) {
            abort(404);
        }

        return [$message, $ticket];
    }

    private function scheduleFiles(iterable $paths): void
    {
        $files = app(SupportFiles::class);
        foreach ($paths as $path) {
            $files->scheduleDeletion($path);
        }
    }

    private function idle(int $id): void
    {
        if (app(Notifications::class)->busy('support', (string) $id)) {
            throw new HttpException(409, __('notifications.busy'));
        }
    }

    public function register(TrashRegistry $registry): void
    {
        $registry->register(
            new TrashSource(
                'support.tickets',
                'support::messages.trash.tickets',
                ['support.manage'],
                ['support.manage'],
                fn (User $user) => Ticket::query()
                    ->join('users', 'users.id', '=', 'support__tickets.user_id')
                    ->whereNotNull('support__tickets.deleted_at')
                    ->select(['support__tickets.id as id', 'support__tickets.deleted_at as deleted_at'])
                    ->selectRaw(
                        'CASE WHEN users.anonymized_at IS NOT NULL THEN ? ELSE support__tickets.subject END as title',
                        [__('support::messages.privacy.removed_ticket')],
                    )
                    ->selectRaw('CASE WHEN users.anonymized_at IS NOT NULL THEN ? ELSE NULL END as restore_reason', [
                        'trash.restore_anonymized',
                    ]),
                fn (User $actor, int $id) => app(SupportTrash::class)->restoreTicket($actor, $id),
                purgePermissions: ['support.purge'],
                purge: fn (User $actor, int $id) => app(SupportTrash::class)->purgeTicket($actor, $id),
            ),
        );
        $registry->register(
            new TrashSource(
                'support.messages',
                'support::messages.trash.messages',
                ['support.manage'],
                ['support.manage'],
                fn (User $user) => Message::query()
                    ->join('support__tickets', 'support__tickets.id', '=', 'support__messages.ticket_id')
                    ->leftJoin('users as author', 'author.id', '=', 'support__messages.user_id')
                    ->join('users as ticket_author', 'ticket_author.id', '=', 'support__tickets.user_id')
                    ->whereNull('support__tickets.deleted_at')
                    ->whereNotNull('support__messages.deleted_at')
                    ->select(['support__messages.id as id', 'support__messages.deleted_at as deleted_at'])
                    ->selectRaw(
                        'CASE WHEN ticket_author.anonymized_at IS NOT NULL THEN ? ELSE support__tickets.subject END as title',
                        [__('support::messages.privacy.removed_ticket')],
                    )
                    ->selectRaw(
                        "CASE WHEN support__messages.user_id IS NULL OR author.anonymized_at IS NOT NULL OR TRIM(support__messages.body) = '' THEN ? ELSE NULL END as restore_reason",
                        ['trash.restore_anonymized'],
                    ),
                fn (User $actor, int $id) => app(SupportTrash::class)->restoreMessage($actor, $id),
                purgePermissions: ['support.purge'],
                purge: fn (User $actor, int $id) => app(SupportTrash::class)->purgeMessage($actor, $id),
            ),
        );
    }
}
