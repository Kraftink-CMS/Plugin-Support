<?php

namespace KraftInk\Plugins\Support\Http\Controllers;

use App\Kraft\Html;
use App\Models\Accounts\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use KraftInk\Plugins\Support\Models\Draft;
use KraftInk\Plugins\Support\Models\FileAttachment;
use KraftInk\Plugins\Support\Services\SupportAccess;
use KraftInk\Plugins\Support\Services\SupportDrafts;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DraftController
{
    public function discard(Request $request): JsonResponse
    {
        $data = $request->validate([
            'context' => ['required', 'regex:/^(?:ticket|category)_[1-9][0-9]{0,18}$/D'],
        ]);
        DB::transaction(function () use ($request, $data) {
            User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            app(SupportDrafts::class)->forget($request->user()->id, $data['context']);
        }, 3);

        return response()
            ->json(['success' => true, 'data' => ['removed' => true]])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'context' => ['required', 'regex:/^(?:ticket|category)_[1-9][0-9]{0,18}$/D'],
            'subject' => 'nullable|string|max:180',
            'body' => 'nullable|string|max:30000',
            'internal' => 'boolean',
            'custom_fields' => 'sometimes|array',
            'custom_fields.*' => 'nullable|string|max:5000',
        ]);

        return DB::transaction(function () use ($request, $data) {
            User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            [$kind, $id] = explode('_', $data['context']);
            $access = app(SupportAccess::class);
            $category = null;
            $fields = [];
            if ($kind === 'ticket') {
                $ticket = $access->ticket($request->user(), (int) $id);
                if (!$access->canReply($ticket)) {
                    throw new HttpException(422);
                }
            } else {
                $category = $access->categories($request->user())->firstWhere('id', (int) $id);
                if (!$category) {
                    throw new HttpException(403);
                }
                $fields = array_intersect_key(
                    $data['custom_fields'] ?? [],
                    $category->fields()->forRequesters()->pluck('id')->flip()->all(),
                );
            }
            if (
                !Draft::query()
                    ->where('user_id', $request->user()->id)
                    ->where('context', $data['context'])
                    ->where('expires_at', '>', now())
                    ->exists() &&
                Draft::query()->where('user_id', $request->user()->id)->where('expires_at', '>', now())->count() >= 20
            ) {
                throw ValidationException::withMessages([
                    'body' => __('support::messages.drafts.draft_limit'),
                ]);
            }
            Draft::query()->updateOrCreate(
                ['user_id' => $request->user()->id, 'context' => $data['context']],
                [
                    'payload' => [
                        'subject' => $data['subject'] ?? '',
                        'body' => $category?->structured_form_only ? '' : Html::clean($data['body'] ?? ''),
                        'internal' => $request->user()->hasPermission('support.manage') && $request->boolean('internal'),
                        'custom_fields' => $fields,
                    ],
                    'expires_at' => now()->addDay(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
            FileAttachment::query()
                ->where('user_id', $request->user()->id)
                ->where('context', $data['context'])
                ->whereNull('message_id')
                ->where('expires_at', '>', now())
                ->update(['expires_at' => now()->addDay()]);

            return response()
                ->json(['success' => true, 'data' => ['saved' => true]])
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }
}
