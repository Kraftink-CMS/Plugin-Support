<?php

namespace KraftInk\Plugins\Support\Services;

use App\Kraft\Auth\RoleAssignments;
use App\Models\Accounts\Role;
use App\Models\Accounts\User;
use KraftInk\Plugins\Support\Models\Category;
use KraftInk\Plugins\Support\Models\CategoryRole;
use KraftInk\Plugins\Support\Models\Ticket;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SupportAccess
{
    public function available(?User $user): bool
    {
        if (!$user || !$user->hasPermission('support.view')) {
            return false;
        }
        if ($this->categories($user)->isNotEmpty()) {
            return true;
        }

        return Ticket::query()
            ->whereNull('deleted_at')
            ->when(!$user->hasPermission('support.manage'), fn ($query) => $query->where('user_id', $user->id))
            ->exists();
    }

    public function canReply(object $ticket): bool
    {
        return $ticket->status !== 'closed';
    }

    public function canManageMessage(?User $user, object $ticket, object $message): bool
    {
        return $user &&
            ($user->hasPermission('support.manage') ||
                (!$message->internal && (int) $message->user_id === $user->id && $this->canReply($ticket)));
    }

    public function categories(User $user): Collection
    {
        if (!$user->hasPermission('support.view')) {
            return collect();
        }
        $roles = $user->effectiveRoles()->pluck('id')->all();
        $allowed = CategoryRole::query()->whereIn('role_id', $roles)->pluck('category_id')->flip();

        return Category::query()
            ->where('enabled', true)
            ->where('locale', app()->getLocale())
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->filter(function ($category) use ($allowed, $user) {
                return (!$category->permission || $user->hasPermission($category->permission)) &&
                    ($category->access === 'members' ||
                        ($category->access === 'roles' && $allowed->has($category->id)));
            });
    }

    public function agents(): Builder
    {
        $managerRoles = [];
        $accessRoles = [];
        foreach (Role::query()->get() as $role) {
            $permissions = $role->effectivePermissions();
            if (array_intersect(['*', 'support.manage'], $permissions)) {
                $managerRoles[] = $role->id;
            }
            if (array_intersect(['*', 'support.view', 'admin.access'], $permissions)) {
                $accessRoles[] = $role->id;
            }
        }
        $assignments = app(RoleAssignments::class);

        return User::members()
            ->whereIn('id', $assignments->query($managerRoles)->select('user_id'))
            ->whereIn('id', $assignments->query($accessRoles)->select('user_id'))
            ->whereNull('anonymized_at')
            ->where('import_locked', false)
            ->where('banned_permanently', false)
            ->where(fn ($query) => $query->whereNull('banned_until')->orWhere('banned_until', '<=', now()))
            ->orderBy('username');
    }

    public function canViewTicket(User $user, Ticket $ticket): bool
    {
        return $ticket->deleted_at === null && $this->canAccessTickets($user) && $this->ownsOrManages($user, $ticket);
    }

    public function ticket(User $user, int $id, bool $lock = false): Ticket
    {
        if (!$this->canAccessTickets($user)) {
            throw new HttpException(403);
        }
        $query = Ticket::query()->where('id', $id)->whereNull('deleted_at');
        $ticket = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($ticket === null) {
            abort(404);
        }
        if (!$this->ownsOrManages($user, $ticket)) {
            throw new HttpException(403);
        }

        return $ticket;
    }

    public function canAccessTickets(?User $user): bool
    {
        $permissions = array_fill_keys($user?->effectivePermissions() ?? [], true);

        return isset($permissions['*']) || isset($permissions['support.view']) ||
            isset($permissions['admin.access'], $permissions['support.manage']);
    }

    private function ownsOrManages(User $user, Ticket $ticket): bool
    {
        return $ticket->user_id === $user->id || $user->hasPermission('support.manage');
    }
}
