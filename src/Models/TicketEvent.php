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
 * @property int $ticket_id
 * @property int|null $actor_id
 * @property string $event
 * @property array|null $data
 * @property Carbon $created_at
 * @property-read User|null $actor
 */
class TicketEvent extends Model
{
    protected $table = 'support__ticket_events';

    protected $guarded = ['id'];

    public const null UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'ticket_id' => 'integer',
            'actor_id' => 'integer',
            'data' => 'array',
            'created_at' => 'datetime',
            'id' => 'integer',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
