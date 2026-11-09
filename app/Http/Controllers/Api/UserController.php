<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\EntityResource;
use App\Http\Resources\UserResource;
use App\Services\UserDirectory;
use App\Services\UserService;
use Illuminate\Http\Request;

class UserController extends ApiController
{
    public function __construct(
        private readonly UserService $users,
        private readonly UserDirectory $directory,
    ) {}

    public function index(Request $request)
    {
        if ($request->filled('email')) {
            $user = $this->users->findByEmail($request->string('email')->toString());

            return $user ? new EntityResource($user) : response()->json(null);
        }

        return UserResource::collection(
            \App\Models\User::query()->orderBy('first_name')->get(),
        );
    }

    public function show(string $id)
    {
        $user = $this->users->get($id);
        abort_unless($user, 404, 'Utilisateur introuvable');

        return new UserResource(\App\Models\User::query()->findOrFail($id));
    }

    public function store(Request $request)
    {
        $data = $this->users->create($request->all());

        return new UserResource(\App\Models\User::query()->findOrFail($data['id']));
    }

    public function update(Request $request, string $id)
    {
        $this->users->update($id, $request->all());

        return new UserResource(\App\Models\User::query()->findOrFail($id));
    }

    public function roles()
    {
        return $this->items($this->directory->roles());
    }
}
