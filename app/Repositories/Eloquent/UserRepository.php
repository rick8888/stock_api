<?php

namespace App\Repositories\Eloquent;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Collection;

class UserRepository implements UserRepositoryInterface
{
    public function findByEmail(string $email): ?User
    {
        return User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->first();
    }

    public function findById(string $id): ?User
    {
        return User::query()->find($id);
    }

    public function all(): Collection
    {
        return User::query()->orderBy('first_name')->get();
    }
}
