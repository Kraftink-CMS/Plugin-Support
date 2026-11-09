<?php

namespace KraftInk\Plugins\Support\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int $category_id
 * @property string $key
 * @property string $label
 * @property string $type
 * @property array|null $options
 * @property bool $required
 * @property bool $staff_only
 * @property bool $enabled
 * @property int $position
 * @property-read Category|null $category
 */
class Field extends Model
{
    public const array TYPES = ['text', 'textarea', 'email', 'select', 'checkbox', 'number'];

    protected $table = 'support__fields';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'options' => 'array',
            'required' => 'boolean',
            'staff_only' => 'boolean',
            'enabled' => 'boolean',
            'position' => 'integer',
            'id' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function scopeForRequesters(Builder $query): Builder
    {
        return $query->where('enabled', true)->where('staff_only', false);
    }

    public function fieldValues(): HasMany
    {
        return $this->hasMany(FieldValue::class, 'field_id');
    }
}
