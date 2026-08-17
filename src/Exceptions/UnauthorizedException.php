<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Exceptions;

use ElPandaPe\Bouncer\Support\Config;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;

/**
 * The end-user-facing denial. Messages come from the translation files, and
 * naming the missing permission or role is opt-in: leaking the authorization
 * model in 403 responses is a footgun, so the default stays generic.
 */
final class UnauthorizedException extends AuthorizationException
{
    /** @var list<string> */
    private array $requiredPermissions = [];

    /** @var list<string> */
    private array $requiredRoles = [];

    /**
     * @param  list<string>  $permissions
     */
    public static function forPermissions(array $permissions, ?Throwable $previous = null): self
    {
        $message = Config::displayPermissionInException() && $permissions !== []
            ? trans('bouncer::bouncer.unauthorized_permission', ['permission' => implode(', ', $permissions)])
            : self::fallbackMessage($previous);

        $exception = self::make(is_string($message) ? $message : null, $previous);
        $exception->requiredPermissions = $permissions;

        return $exception;
    }

    /**
     * @param  list<string>  $roles
     */
    public static function forRoles(array $roles, ?Throwable $previous = null): self
    {
        $message = Config::displayRoleInException() && $roles !== []
            ? trans('bouncer::bouncer.unauthorized_role', ['role' => implode(', ', $roles)])
            : self::fallbackMessage($previous);

        $exception = self::make(is_string($message) ? $message : null, $previous);
        $exception->requiredRoles = $roles;

        return $exception;
    }

    /**
     * @return list<string>
     */
    public function getRequiredPermissions(): array
    {
        return $this->requiredPermissions;
    }

    /**
     * @return list<string>
     */
    public function getRequiredRoles(): array
    {
        return $this->requiredRoles;
    }

    /**
     * The gate response survives the wrap: HTTP status overrides, error codes
     * and the response payload keep driving Laravel's exception rendering.
     */
    private static function make(?string $message, ?Throwable $previous): self
    {
        $exception = new self($message, previous: $previous);

        if ($previous instanceof AuthorizationException) {
            $exception->code = $previous->getCode();
            $exception->setResponse($previous->response());

            if ($previous->hasStatus()) {
                $exception->withStatus($previous->status());
            }
        }

        return $exception;
    }

    private static function fallbackMessage(?Throwable $previous): string
    {
        // A policy's own denial message survives; Laravel's untranslated
        // default does not — the generic message comes from our lang files.
        $laravelDefault = 'This action is unauthorized.';

        if (
            $previous instanceof Throwable
            && $previous->getMessage() !== ''
            && $previous->getMessage() !== $laravelDefault
        ) {
            return $previous->getMessage();
        }

        $message = trans('bouncer::bouncer.unauthorized');

        return is_string($message) ? $message : $laravelDefault;
    }
}
