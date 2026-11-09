<?php

namespace KraftInk\Plugins\Support\Services;

use App\Kraft\Html;
use App\Kraft\Media\FileDeletionQueue;
use App\Kraft\Media\Images;
use App\Models\Accounts\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use KraftInk\Plugins\Support\Models\FileAttachment;
use RuntimeException;
use Throwable;
use DOMDocument;

class SupportFiles
{
    private const array Types = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
    ];

    public function store(UploadedFile $file, User $actor, string $context, bool $imageOnly = false): FileAttachment
    {
        $field = $imageOnly ? 'file' : 'attachment';
        $mime = $file->isValid() ? $file->getMimeType() : null;
        if (
            $mime === null ||
            !isset(self::Types[$mime]) ||
            $file->getSize() > 5242880 ||
            ($imageOnly && !str_starts_with($mime, 'image/'))
        ) {
            throw ValidationException::withMessages([$field => __('support::messages.attachments.invalid_file')]);
        }
        $image = null;
        if (str_starts_with($mime, 'image/')) {
            $images = app(Images::class);
            $image = $images->inspect($file);
            if (
                !$image ||
                $image->width * $image->height > 8000000 ||
                !$images->canDecode($image)
            ) {
                throw ValidationException::withMessages([
                    $field => __('support::messages.attachments.invalid_file'),
                ]);
            }
            $image = $images->prepareUpload($file, $image, field: $field);
        }

        $size = $image?->size ?? $file->getSize();
        $path = null;
        try {
            return DB::transaction(function () use (
                $file,
                $actor,
                $context,
                $mime,
                $size,
                $imageOnly,
                $image,
                $field,
                &$path,
            ) {
                User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $hash = $image?->sha256() ?? hash_file('sha256', $file->getRealPath());
                $existing = FileAttachment::query()
                    ->where('user_id', $actor->id)
                    ->where('context', $context)
                    ->where('inline', $imageOnly)
                    ->whereNull('message_id')
                    ->where('checksum', $hash)
                    ->where('expires_at', '>', now())
                    ->first();
                if ($existing && Storage::disk('local')->exists($existing->path)) {
                    return $existing;
                }
                if (
                    FileAttachment::query()->where('user_id', $actor->id)->sum('size') + $size > 134217728 ||
                    FileAttachment::query()->where('user_id', $actor->id)->whereNull('message_id')->count() >= 30
                ) {
                    throw ValidationException::withMessages([
                        $field => __('support::messages.attachments.file_quota'),
                    ]);
                }
                $extension = $image?->extension ?? self::Types[$mime];
                $path = 'support/files/'.Str::uuid().'.'.$extension;
                DB::afterRollBack(fn () => $this->scheduleDeletion($path));
                if (
                    !Storage::disk('local')->putFileAs('support/files', $image?->path ?? $file, basename($path), [
                        'visibility' => 'private',
                    ])
                ) {
                    throw new RuntimeException(__('support::messages.attachments.file_failed'));
                }
                $name = mb_substr(
                    preg_replace('/[\\\\\/\p{C}]/u', '_', $file->getClientOriginalName()) ?:
                    'attachment.'.$extension,
                    0,
                    180,
                );

                return FileAttachment::query()->create([
                    'user_id' => $actor->id,
                    'context' => $context,
                    'inline' => $imageOnly,
                    'path' => $path,
                    'name' => $name,
                    'mime' => $image?->mime ?? $mime,
                    'size' => $size,
                    'checksum' => $hash,
                    'expires_at' => now()->addHours(2),
                ]);
            }, 3);
        } catch (Throwable $exception) {
            if ($path !== null) {
                $this->scheduleDeletion($path);
            }
            throw $exception;
        }
    }

    public function bind(
        int $message,
        User $actor,
        string $context,
        string $body,
        ?FileAttachment $attachment = null,
    ): void {
        $document = new DOMDocument();
        $document->loadHTML(
            '<meta charset="UTF-8">'.Html::clean($body),
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        $sample = route('plugins.support.public.files.show', 0);
        $prefix = rtrim(parse_url($sample, PHP_URL_PATH), '0');
        $ids = $attachment ? [$attachment->id] : [];
        foreach ($document->getElementsByTagName('img') as $image) {
            $parts = parse_url($image->getAttribute('src'));
            if (
                $parts !== false &&
                (!isset($parts['host']) || strcasecmp($parts['host'], parse_url($sample, PHP_URL_HOST)) === 0) &&
                preg_match('~^'.preg_quote($prefix, '~').'([1-9][0-9]*)$~D', $parts['path'] ?? '', $match)
            ) {
                $ids[] = (int) $match[1];
            }
        }
        if (count(array_unique($ids)) > 6) {
            throw ValidationException::withMessages(['attachment' => __('support::messages.attachments.file_quota')]);
        }
        foreach (array_unique($ids) as $id) {
            $file = FileAttachment::query()->where('id', $id)->lockForUpdate()->first();
            if ($file && (int) $file->message_id === $message && Storage::disk('local')->exists($file->path)) {
                continue;
            }
            if (
                !$file ||
                $file->message_id ||
                (int) $file->user_id !== $actor->id ||
                $file->context !== $context ||
                !$file->expires_at ||
                now()->gte($file->expires_at) ||
                !Storage::disk('local')->exists($file->path)
            ) {
                throw ValidationException::withMessages([
                    'attachment' => __('support::messages.attachments.file_expired'),
                ]);
            }
            FileAttachment::query()
                ->where('id', $id)
                ->update(['message_id' => $message, 'expires_at' => null]);
        }
    }

    public function safePath(string $path): bool
    {
        return preg_match('~^support/files/[a-f0-9-]{36}\.(?:png|jpg|webp|pdf|txt)$~D', $path) === 1;
    }

    public function scheduleDeletion(string $path): void
    {
        if ($this->safePath($path)) {
            app(FileDeletionQueue::class)->schedule($path, 'support/files', ['support__files' => 'path']);
        }
    }

    public function cleanup(int $limit = 200, bool $dryRun = false): int
    {
        $cutoff = now();
        $expired = FileAttachment::query()->whereNull('message_id')->where('expires_at', '<=', $cutoff);
        if ($dryRun) {
            return $expired->count();
        }
        $ids = $expired->orderBy('id')->limit(max(1, min(1000, $limit)))->pluck('id');

        return DB::transaction(function () use ($ids, $cutoff): int {
            $files = FileAttachment::query()
                ->whereIn('id', $ids)
                ->whereNull('message_id')
                ->where('expires_at', '<=', $cutoff)
                ->lockForUpdate()
                ->get();
            foreach ($files as $file) {
                /** @var FileAttachment $file */
                FileAttachment::query()->where('id', $file->id)->delete();
                $this->scheduleDeletion($file->path);
            }

            return $files->count();
        });
    }
}
