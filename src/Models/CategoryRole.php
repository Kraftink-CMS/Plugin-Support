<?php

namespace KraftInk\Plugins\Support\Models;

use Illuminate\Database\Eloquent\Builder;
use App\Models\Accounts\Role;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @mixin Builder<static>
 *
 * @property int $category_id
 * @property int $role_id
 * @property-read Category|null $category
 */
class CategoryRole extends Pivot
{
    protected $table = 'support__category_roles';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'role_id' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }
}
