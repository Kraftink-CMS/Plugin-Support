<?php

namespace KraftInk\Plugins\Support\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int $ticket_id
 * @property int|null $user_id
 * @property string $body
 * @property bool $internal
 * @property Carbon|null $deleted_at
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read Collection<int, FileAttachment> $files
 * @property-read FileAttachment|null $attachment
 */
class Message extends Model
{
    protected $table = 'support__messages';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'ticket_id' => 'integer',
            'user_id' => 'integer',
            'internal' => 'boolean',
            'deleted_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'id' => 'integer',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(MessageRevision::class, 'message_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(FileAttachment::class, 'message_id');
    }

    public function attachment(): HasOne
    {
        return $this->hasOne(FileAttachment::class, 'message_id')->where('inline', false);
    }

    public function recordRevision(int $actor): MessageRevision
    {
        return $this->revisions()->create([
            'user_id' => $actor,
            'body' => $this->body,
            'deleted_at' => $this->deleted_at,
        ]);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (!$user->hasPermission('support.manage')) {
            $query
                ->where('support__messages.internal', false)
                ->where(
                    fn (Builder $messages) => $messages
                        ->whereNull('support__messages.deleted_at')
                        ->orWhere('support__messages.user_id', $user->id),
                );
        }

        return $query;
    }
}
