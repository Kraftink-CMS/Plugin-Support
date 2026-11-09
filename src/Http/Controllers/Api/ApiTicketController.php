<?php

namespace KraftInk\Plugins\Support\Http\Controllers\Api;

use App\Kraft\System\ApiPagination;
use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use KraftInk\Plugins\Support\Models\Ticket;

class ApiTicketController
{
    public function index(Request $request): JsonResponse
    {
        $perPage = ApiPagination::perPage($request);
        $tickets = $this->ownedTickets($request->user())
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['id', 'subject', 'status', 'created_at', 'updated_at']);

        return response()->json(['success' => true, 'data' => ApiPagination::data($tickets)]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $perPage = ApiPagination::perPage($request);
        $ticket = $this->ownedTickets($request->user())->find($id, ['id', 'subject', 'status', 'created_at', 'updated_at']);
        if ($ticket === null) {
            abort(404);
        }
        $messages = $ticket
            ->messages()
            ->where('internal', false)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->paginate($perPage, ['id', 'user_id', 'body', 'created_at']);

        return response()->json([
            'success' => true,
            'data' => [
                'ticket' => $ticket,
                ...ApiPagination::data($messages, 'messages'),
            ],
        ]);
    }

    private function ownedTickets(User $user): Builder
    {
        return Ticket::query()->whereBelongsTo($user, 'user')->whereNull('deleted_at');
    }
}
