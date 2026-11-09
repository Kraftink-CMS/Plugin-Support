<?php

namespace KraftInk\Plugins\Support\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int $ticket_id
 * @property int|null $field_id
 * @property string $field_label
 * @property bool $staff_only
 * @property string|null $value
 */
class FieldValue extends Model
{
    protected $table = 'support__field_values';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'ticket_id' => 'integer',
            'field_id' => 'integer',
            'staff_only' => 'boolean',
            'id' => 'integer',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(Field::class, 'field_id');
    }
}
