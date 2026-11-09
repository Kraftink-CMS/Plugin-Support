<?php

namespace KraftInk\Plugins\Support\Services;

use KraftInk\Plugins\Support\Models\Draft;

class SupportDrafts
{
    public function get(int $user, string $context): array
    {
        $row = Draft::query()
            ->where('user_id', $user)
            ->where('context', $context)
            ->where('expires_at', '>', now())
            ->first();

        return $row ? $row->payload : [];
    }

    public function forget(int $user, string $context): void
    {
        Draft::query()->where('user_id', $user)->where('context', $context)->delete();
    }

    public function cleanup(bool $dryRun = false): int
    {
        $cutoff = now();
        $expired = Draft::query()->where('expires_at', '<=', $cutoff);
        if ($dryRun) {
            return $expired->count();
        }
        $count = 0;
        $expired->orderBy('id')->chunkById(500, function ($drafts) use ($cutoff, &$count): void {
            $count += Draft::query()->whereIn('id', $drafts->pluck('id'))->where('expires_at', '<=', $cutoff)->delete();
        });

        return $count;
    }
}
