<?php

namespace KraftInk\Plugins\Support\Models;

use App\Models\Accounts\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property string $name
 * @property string $locale
 * @property string $icon
 * @property string|null $introduction
 * @property string $access
 * @property string|null $permission
 * @property int|null $first_response_minutes
 * @property int|null $resolution_minutes
 * @property int|null $auto_close_hours
 * @property bool $enabled
 * @property int $position
 * @property-read Collection<int, Field> $fields
 * @property-read Collection<int, CategoryRole> $categoryRoles
 * @property-read Collection<int, Role> $roles
 */
class Category extends Model
{
    protected $table = 'support__categories';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'first_response_minutes' => 'integer',
            'resolution_minutes' => 'integer',
            'structured_form_only' => 'boolean',
            'auto_close_hours' => 'integer',
            'auto_close_unanswered' => 'boolean',
            'enabled' => 'boolean',
            'position' => 'integer',
            'id' => 'integer',
        ];
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'category_id');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(Field::class, 'category_id');
    }

    public function categoryRoles(): HasMany
    {
        return $this->hasMany(CategoryRole::class, 'category_id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'support__category_roles', 'category_id', 'role_id')->using(
            CategoryRole::class,
        );
    }
}
