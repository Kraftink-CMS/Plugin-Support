<?php

namespace KraftInk\Plugins\Support\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use KraftInk\Plugins\Support\Models\FileAttachment;
use KraftInk\Plugins\Support\Models\Message;
use KraftInk\Plugins\Support\Services\SupportAccess;
use KraftInk\Plugins\Support\Services\SupportFiles;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FileController
{
    public function __construct(private SupportFiles $files)
    {
    }

    public function upload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => 'required|file|max:5120',
            'category' => 'nullable|integer',
            'ticket' => 'nullable|integer',
        ]);
        $access = app(SupportAccess::class);
        if ($request->filled('ticket')) {
            $ticket = $access->ticket($request->user(), $data['ticket']);
            if (!$access->canReply($ticket)) {
                throw new HttpException(422);
            }
            $context = 'ticket_'.$ticket->id;
        } else {
            if (!$access->categories($request->user())->contains('id', (int) ($data['category'] ?? 0))) {
                throw new HttpException(403);
            }
            $context = 'category_'.$data['category'];
        }
        $file = $this->files->store($data['file'], $request->user(), $context, imageOnly: true);

        return response()
            ->json(['success' => true, 'data' => ['location' => route('plugins.support.public.files.show', $file->id)]])
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, int $id): StreamedResponse
    {
        $file = FileAttachment::query()->findOrFail($id);
        if ($file->message_id) {
            $this->authorizeMessage($request, $file->message);
        } else {
            if (!((int) $file->user_id === $request->user()->id && $file->expires_at && now()->lt($file->expires_at))) {
                abort(404);
            }
            if (preg_match('/^ticket_([1-9][0-9]*)$/D', $file->context, $match)) {
                app(SupportAccess::class)->ticket($request->user(), (int) $match[1]);
            }
        }

        return $this->response($file);
    }

    public function download(Request $request, int $id): StreamedResponse
    {
        $message = Message::query()->with('attachment')->find($id);
        $file = $message?->attachment;
        if (!$file) {
            abort(404);
        }
        $this->authorizeMessage($request, $message);

        return $this->response($file, download: true);
    }

    private function authorizeMessage(Request $request, ?Message $message): void
    {
        if (!$message || $message->deleted_at) {
            abort(404);
        }
        app(SupportAccess::class)->ticket($request->user(), $message->ticket_id);
        if ($message->internal && !$request->user()->hasPermission('support.manage')) {
            throw new HttpException(403);
        }
    }

    private function response(FileAttachment $file, bool $download = false): StreamedResponse
    {
        $disk = Storage::disk('local');
        if (!$this->files->safePath($file->path) || !$disk->exists($file->path)) {
            abort(404);
        }
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Content-Type' => $file->mime,
        ];

        return !$download && str_starts_with($file->mime, 'image/')
            ? $disk->response($file->path, $file->name, $headers)
            : $disk->download($file->path, $file->name, $headers);
    }
}
