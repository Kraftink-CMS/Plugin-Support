<?php

namespace KraftInk\Plugins\Support\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int $user_id
 * @property int $category_id
 * @property int|null $assigned_to
 * @property string $subject
 * @property string $status
 * @property string $priority
 * @property Carbon|null $first_staff_reply_at
 * @property Carbon|null $last_staff_reply_at
 * @property Carbon|null $first_response_due_at
 * @property Carbon|null $resolution_due_at
 * @property Carbon|null $escalated_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read Category|null $category
 */
class Ticket extends Model
{
    protected $table = 'support__tickets';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'category_id' => 'integer',
            'assigned_to' => 'integer',
            'first_staff_reply_at' => 'datetime',
            'last_staff_reply_at' => 'datetime',
            'first_response_due_at' => 'datetime',
            'resolution_due_at' => 'datetime',
            'escalated_at' => 'datetime',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
            'deleted_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'ticket_id');
    }

    public function fieldValues(): HasMany
    {
        return $this->hasMany(FieldValue::class, 'ticket_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TicketEvent::class, 'ticket_id');
    }
}
