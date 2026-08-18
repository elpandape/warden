<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Http\Middleware;

use Closure;
use ElPandaPe\Warden\Exceptions\UnauthorizedException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware: ->middleware('warden.permission:edit-site') — all of.
 * Checks run through the Gate, so policies keep their say.
 */
final class RequiresPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        // Guests and parameterless registrations fail closed: a guard with
        // nothing to require is a misconfiguration, not an open door.
        if ($user === null || $permissions === []) {
            throw UnauthorizedException::forPermissions(array_values($permissions));
        }

        foreach ($permissions as $permission) {
            if (Gate::forUser($user)->denies($permission)) {
                throw UnauthorizedException::forPermissions(array_values($permissions));
            }
        }

        $response = $next($request);

        assert($response instanceof Response);

        return $response;
    }
}
