<?php

namespace App\Http\Middleware;

use App\Services\EntitlementService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModule
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$modules): Response
    {
        $user = $request->user('api') ?? $request->user();
        if (! $user) {
            return response()->json(['message' => 'Non authentifié.'], 401);
        }
        if (($user->role ?? '') === 'SUPER_ADMIN') {
            return $next($request);
        }
        foreach ($modules as $module) {
            if ($this->entitlements->hasModule($module)) {
                return $next($request);
            }
        }

        $label = implode(' / ', $modules);

        return response()->json([
            'message' => "Module « {$label} » non inclus dans votre plan.",
        ], 403);
    }
}
