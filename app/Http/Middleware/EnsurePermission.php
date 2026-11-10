<?php

namespace App\Http\Middleware;

use App\Support\RolePermissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user('api') ?? $request->user();
        if (! $user) {
            return response()->json(['message' => 'Non authentifié.'], 401);
        }

        $role = (string) ($user->role ?? '');
        foreach ($permissions as $permission) {
            if (RolePermissions::allows($role, $permission)) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'Permission refusée.'], 403);
    }
}
