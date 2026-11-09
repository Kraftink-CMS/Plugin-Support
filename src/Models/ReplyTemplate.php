<?php

namespace KraftInk\Plugins\Support\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int|null $category_id
 * @property string $title
 * @property string $locale
 * @property string $kind
 * @property string $body
 * @property bool $enabled
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Category|null $category
 */
class ReplyTemplate extends Model
{
    protected $table = 'support__templates';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'enabled' => 'boolean',
            'position' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'id' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function scopeAvailableFor(Builder $query, int $categoryId, string $kind, string $locale): void
    {
        $query
            ->where('kind', $kind)
            ->where('enabled', true)
            ->where('locale', $locale)
            ->where(fn (Builder $templates) => $templates->whereNull('category_id')->orWhere('category_id', $categoryId));
    }
}
