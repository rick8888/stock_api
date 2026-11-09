<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InvalidCredentialsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\AuthTokenResource;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
    ) {}

    public function login(LoginRequest $request): AuthTokenResource|JsonResponse
    {
        try {
            return new AuthTokenResource($this->auth->login(
                $request->string('email')->toString(),
                $request->string('password')->toString(),
            ));
        } catch (InvalidCredentialsException $e) {
            return response()->json(['message' => $e->getMessage()], 401);
        }
    }

    public function logout(): JsonResponse
    {
        $this->auth->logout();

        return response()->json(['message' => 'Déconnecté.']);
    }

    public function refresh(): AuthTokenResource
    {
        return new AuthTokenResource($this->auth->refresh());
    }

    public function me(): UserResource
    {
        return new UserResource($this->auth->me());
    }
}
