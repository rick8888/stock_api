<?php

namespace App\Services;

use App\Exceptions\InvalidCredentialsException;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\Hash;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class AuthService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
    ) {}

    public function login(string $email, string $password): array
    {
        $user = $this->users->findByEmail($email);
        if (! $user || ! Hash::check($password, $user->password)) {
            throw new InvalidCredentialsException;
        }

        $token = JWTAuth::fromUser($user);

        return $this->tokenPayload($user, $token);
    }

    public function logout(): void
    {
        JWTAuth::invalidate(JWTAuth::getToken());
    }

    public function refresh(): array
    {
        $token = JWTAuth::refresh(JWTAuth::getToken());
        $user = JWTAuth::setToken($token)->toUser();

        return $this->tokenPayload($user, $token);
    }

    public function me(): User
    {
        $user = JWTAuth::parseToken()->authenticate();
        if (! $user instanceof User) {
            throw new InvalidCredentialsException;
        }

        return $user;
    }

    private function tokenPayload(User $user, string $token): array
    {
        $ttl = (int) config('jwt.ttl', 60);

        return [
            'user' => $user,
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => $ttl * 60,
        ];
    }
}
