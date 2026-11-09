<?php

namespace KraftInk\Plugins\Support\Http\Controllers;

use App\Kraft\Audit;
use App\Kraft\Html;
use App\Models\Accounts\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use KraftInk\Plugins\Support\Models\Message;
use KraftInk\Plugins\Support\Models\MessageRevision;
use KraftInk\Plugins\Support\Services\SupportAccess;
use KraftInk\Plugins\Support\Services\SupportFiles;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MessageHistoryController
{
    public function index(Request $request, int $id): View
    {
        [$message, $ticket] = $this->message($request->user(), $id);

        return view('support::default.messages.history', [
            'message' => $message,
            'ticket' => $ticket,
            'revisions' => MessageRevision::query()->where('message_id', $id)->latest('id')->paginate(25),
        ]);
    }

    public function edit(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['body' => 'required|string|min:2|max:30000']);
        $body = Html::clean($data['body']);
        $text = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!preg_match('/[^\s\p{Z}\x{200B}\x{FEFF}]/u', $text) && !preg_match('/<img\b/i', $body)) {
            throw ValidationException::withMessages(['body' => __('support::messages.history.empty_body')]);
        }
        DB::transaction(function () use ($request, $id, $body) {
            [$message, $ticket] = $this->message($request->user(), $id, true);
            if ($message->deleted_at) {
                throw new HttpException(410);
            }
            $message->recordRevision($request->user()->id);
            app(SupportFiles::class)->bind($id, $request->user(), 'ticket_'.$ticket->id, $body);
            Message::query()
                ->where('id', $id)
                ->update(['body' => $body, 'updated_at' => now()]);
            Audit::record('support.message_updated', ['ticket_id' => $ticket->id, 'message_id' => $id]);
        }, 3);

        return back()->with('status', __('support::messages.tickets.saved'));
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $request->validate(['confirmed' => 'accepted']);
        DB::transaction(function () use ($request, $id) {
            [$message, $ticket] = $this->message($request->user(), $id, true);
            if ((int) Message::query()->where('ticket_id', $ticket->id)->min('id') === $id) {
                throw new HttpException(422, __('support::messages.history.first_message'));
            }
            if (!$message->deleted_at) {
                $message->recordRevision($request->user()->id);
                Message::query()
                    ->where('id', $id)
                    ->update(['deleted_at' => now(), 'updated_at' => now()]);
                Audit::record('support.message_removed', ['ticket_id' => $ticket->id, 'message_id' => $id]);
            }
        }, 3);

        return back()->with('status', __('support::messages.tickets.saved'));
    }

    public function restore(Request $request, int $id, int $revision): RedirectResponse
    {
        DB::transaction(function () use ($request, $id, $revision) {
            [$message, $ticket] = $this->message($request->user(), $id, true);
            if ($message->deleted_at) {
                throw new HttpException(410, __('support::messages.history.unavailable'));
            }
            $snapshot = MessageRevision::query()
                ->where('message_id', $id)
                ->where('id', $revision)
                ->lockForUpdate()
                ->first();
            if ($snapshot === null) {
                abort(404);
            }
            if ($snapshot->anonymized || $snapshot->body === '') {
                throw new HttpException(410, __('support::messages.history.unavailable'));
            }
            app(SupportFiles::class)->bind($id, $request->user(), 'ticket_'.$ticket->id, $snapshot->body);
            $message->recordRevision($request->user()->id);
            Message::query()
                ->where('id', $id)
                ->update([
                    'body' => Html::clean($snapshot->body),
                    'updated_at' => now(),
                ]);
            Audit::record('support.message_restored', [
                'ticket_id' => $ticket->id,
                'message_id' => $id,
                'revision_id' => $revision,
            ]);
        }, 3);

        return back()->with('status', __('support::messages.tickets.saved'));
    }

    private function message(User $actor, int $id, bool $lock = false): array
    {
        $message = Message::query()->where('id', $id)->first();
        if ($message === null) {
            abort(404);
        }
        $ticket = app(SupportAccess::class)->ticket($actor, $message->ticket_id, $lock);
        if ($lock) {
            $message = Message::query()->where('id', $id)->lockForUpdate()->first();
            if ($message === null) {
                abort(404);
            }
        }
        if (!app(SupportAccess::class)->canManageMessage($actor, $ticket, $message)) {
            throw new HttpException(403);
        }

        return [$message, $ticket];
    }
}
