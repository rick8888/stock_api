<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Eloquent\AuditLogRepository;
use Illuminate\Support\Collection;

class UserDirectory
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly AuditLogRepository $audit,
    ) {}

    public function find(string $id): ?array
    {
        $user = $this->users->findById($id);

        return $user ? $this->toArray($user) : null;
    }

    public function all(): Collection
    {
        return $this->users->all()->map(fn (User $user) => $this->toArray($user));
    }

    public function roles(): Collection
    {
        return Role::query()->get()->map(fn (Role $role) => [
            'id' => $role->id,
            'key' => $role->key,
            'name' => $role->name,
            'description' => $role->description,
            'permissions' => $role->permissions ?? [],
        ]);
    }

    public function auditHas(string $action, string $entityId): bool
    {
        return $this->audit->has($action, $entityId);
    }

    public function toArray(User $user): array
    {
        return [
            'id' => $user->id,
            'firstName' => $user->first_name,
            'lastName' => $user->last_name,
            'email' => $user->email,
            'password' => '',
            'role' => $user->role,
            'title' => $user->title,
            'avatarHue' => $user->avatar_hue,
            'warehouseIds' => $user->warehouse_ids ?? [],
            'productIds' => $user->product_ids ?? [],
            'regionIds' => $user->region_ids ?? [],
            'driverId' => $user->driver_id,
            'companyId' => $user->company_id,
        ];
    }
}
