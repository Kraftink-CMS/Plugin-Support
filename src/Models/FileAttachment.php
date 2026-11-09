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
 * @property int|null $message_id
 * @property int|null $user_id
 * @property string $context
 * @property bool $inline
 * @property string $path
 * @property string $name
 * @property string $mime
 * @property int $size
 * @property string $checksum
 * @property Carbon|null $expires_at
 * @property Carbon $created_at
 * @property-read Message|null $message
 * @property-read User|null $user
 */
class FileAttachment extends Model
{
    protected $table = 'support__files';

    protected $guarded = ['id'];

    public const null UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'message_id' => 'integer',
            'user_id' => 'integer',
            'inline' => 'boolean',
            'expires_at' => 'datetime',
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
