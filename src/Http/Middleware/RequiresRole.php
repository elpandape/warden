<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Http\Middleware;

use Closure;
use ElPandaPe\Warden\Actions\ChecksRoles;
use ElPandaPe\Warden\Exceptions\UnauthorizedException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware: ->middleware('warden.role:admin,editor') — any of.
 */
final class RequiresRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $authority = $request->user();

        // Guests and parameterless registrations fail closed.
        if (! $authority instanceof Model || $roles === [] || ! new ChecksRoles($authority)->a(...$roles)) {
            throw UnauthorizedException::forRoles(array_values($roles));
        }

        $response = $next($request);

        assert($response instanceof Response);

        return $response;
    }
}
