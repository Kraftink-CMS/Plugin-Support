<?php

namespace KraftInk\Plugins\Support\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int $message_id
 * @property int|null $user_id
 * @property string $body
 * @property bool $anonymized
 * @property Carbon|null $deleted_at
 * @property Carbon $created_at
 * @property-read Message|null $message
 * @property-read User|null $user
 */
class MessageRevision extends Model
{
    protected $table = 'support__message_revisions';

    protected $guarded = ['id'];

    public const null UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'message_id' => 'integer',
            'user_id' => 'integer',
            'anonymized' => 'boolean',
            'deleted_at' => 'datetime',
            'created_at' => 'datetime',
            'id' => 'integer',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
