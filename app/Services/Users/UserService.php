<?php

namespace App\Services\Users;

use App\Enums\UserStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use App\Models\Role;

class UserService
{
    public function paginate(array $filters, User $performedBy): LengthAwarePaginator
    {
        return User::query()
            ->with('role')
            ->when($performedBy->role?->slug === 'admin', fn ($query) => $query->whereHas('role', fn ($role) => $role->where('slug', 'user')))
            ->when(
                $filters['search'] ?? null,
                function ($query, string $search) {
                    $query->where(function ($query) use ($search) {
                        $query
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
                }
            )
            ->when(
                $filters['role_id'] ?? null,
                fn ($query, int $roleId) =>
                    $query->where('role_id', $roleId)
            )
            ->when(
                $filters['status'] ?? null,
                fn ($query, string $status) =>
                    $query->where('status', $status)
            )
            ->orderBy(
                $filters['sort_by'] ?? 'created_at',
                $filters['sort_direction'] ?? 'desc'
            )
            ->paginate($filters['per_page'] ?? 10)
            ->withQueryString();
    }

    public function get(User $user, User $performedBy): User
    {
        $this->ensureCanManage($performedBy, $user);
        return $user->load('role');
    }

    public function create(array $data, User $performedBy): User
    {
        $this->ensureCanAssignRole($performedBy, (int) $data['role_id']);
        return DB::transaction(function () use ($data) {
            $user = User::create($data);

            return $user->load('role');
        });
    }

    public function update(
        User $user,
        array $data,
        User $performedBy
    ): User {
        $this->ensureCanManage($performedBy, $user);
        if (isset($data['role_id'])) $this->ensureCanAssignRole($performedBy, (int) $data['role_id']);
        $this->protectLastSuperAdmin($user, $data);
        return DB::transaction(function () use (
            $user,
            $data,
            $performedBy
        ) {
            if (empty($data['password'])) {
                unset($data['password']);
            }

            $this->preventSelfDeactivation(
                user: $user,
                data: $data,
                performedBy: $performedBy,
            );

            $previousStatus = $user->status;

            $user->update($data);
            $user->refresh();

            if (
                $previousStatus->canLogin()
                && ! $user->status->canLogin()
            ) {
                $user->tokens()->delete();
            }

            return $user->load('role');
        });
    }

    public function delete(
        User $user,
        User $performedBy
    ): void {
        $this->ensureCanManage($performedBy, $user);
        $this->protectLastSuperAdmin($user, ['delete' => true]);
        if ($performedBy->is($user)) {
            throw new BusinessRuleException(
                'You cannot delete your own account.'
            );
        }

        DB::transaction(function () use ($user) {
            $user->tokens()->delete();
            $user->delete();
        });
    }

    private function ensureCanManage(User $actor, User $target): void
    {
        $target->loadMissing('role');
        if ($actor->role?->slug === 'admin' && $target->role?->slug !== 'user') {
            throw new BusinessRuleException('Administrators can only manage regular users.', 403);
        }
    }

    private function ensureCanAssignRole(User $actor, int $roleId): void
    {
        $role = Role::query()->findOrFail($roleId);
        if ($actor->role?->slug === 'admin' && $role->slug !== 'user') {
            throw new BusinessRuleException('Administrators can only assign the User role.', 403);
        }
    }

    private function protectLastSuperAdmin(User $user, array $data): void
    {
        $user->loadMissing('role');
        if ($user->role?->slug !== 'super_admin') return;
        $newRoleIsSuper = ! isset($data['role_id']) || Role::query()->whereKey($data['role_id'])->where('slug', 'super_admin')->exists();
        $remainsActive = ! isset($data['status']) || (($data['status'] instanceof UserStatus ? $data['status']->value : $data['status']) === UserStatus::Active->value);
        if (($data['delete'] ?? false) || ! $newRoleIsSuper || ! $remainsActive) {
            $activeSuperAdmins = User::query()->where('status', UserStatus::Active->value)->whereHas('role', fn ($query) => $query->where('slug', 'super_admin'))->count();
            if ($activeSuperAdmins <= 1) throw new BusinessRuleException('The last active Super Administrator cannot be removed, deactivated, or demoted.', 422);
        }
    }

    private function preventSelfDeactivation(
        User $user,
        array $data,
        User $performedBy
    ): void {
        if (! $performedBy->is($user)) {
            return;
        }

        if (! isset($data['status'])) {
            return;
        }

        $newStatus = $data['status'] instanceof UserStatus
            ? $data['status']
            : UserStatus::from($data['status']);

        if (! $newStatus->canLogin()) {
            throw new BusinessRuleException(
                'You cannot deactivate your own account.'
            );
        }
    }
}
