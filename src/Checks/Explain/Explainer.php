<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Checks\Explain;

use BackedEnum;
use ElPandaPe\Bouncer\Checks\Resolvers\DatabaseResolver;
use ElPandaPe\Bouncer\Checks\Verdict;
use ElPandaPe\Bouncer\Context;
use ElPandaPe\Bouncer\Support\Name;
use ElPandaPe\Bouncer\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;

/**
 * Always runs the database engine directly: a diagnosis must reflect the
 * rows as they are, never a cached payload.
 */
final readonly class Explainer
{
    public function __construct(private Context $context) {}

    public function explain(Model $authority, string|BackedEnum $permission, Model|string|null $entity = null): AuthorizationExplanation
    {
        $verdict = new DatabaseResolver($this->context)->resolve($authority, Name::of($permission), $entity);

        if ($verdict->isAbstained()) {
            $applicable = ! is_string($entity)
                || $entity === '*'
                || is_subclass_of($entity, Model::class);

            return new AuthorizationExplanation(
                $verdict,
                $applicable ? Cause::NoMatchingGrant : Cause::NotApplicable,
            );
        }

        // The decisive row, visible regardless of the current tenant filter.
        $decisive = $this->context->permissionClass()::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereKey($verdict->permissionKey)
            ->first();

        [$cause, $role] = $this->source($authority, $verdict, $entity);

        return new AuthorizationExplanation($verdict, $cause, $decisive, $role);
    }

    /**
     * How the decisive permission reaches the authority: directly, through a
     * role, or as an everyone-grant — reported most-specific first.
     *
     * @return array{0: Cause, 1: Model|null}
     */
    private function source(Model $authority, Verdict $verdict, Model|string|null $entity): array
    {
        $forbidden = $verdict->isForbidden();
        $roleMorph = (new ($this->context->roleClass()))->getMorphClass();

        $grants = $this->context->grantClass()::query()
            ->where('permission_id', $verdict->permissionKey)
            ->where('forbidden', $forbidden)
            ->get();

        $direct = $grants->first(
            fn (Model $grant): bool => $grant->getAttribute('entity_type') === $authority->getMorphClass()
                && $this->stringable($grant->getAttribute('entity_id')) === $this->stringable($authority->getKey()),
        );

        if ($direct !== null) {
            return [$forbidden ? Cause::ForbiddenDirectly : Cause::GrantedDirectly, null];
        }

        // Only the assignments the resolver itself would use for this check:
        // a restricted role outside its context must not be blamed.
        $roleKeys = [];

        foreach ($this->context->assignedRoleClass()::query()
            ->where('entity_type', $authority->getMorphClass())
            ->where('entity_id', $authority->getKey())
            ->get() as $assignment) {
            $contextType = $assignment->getAttribute('restricted_to_type');
            $contextId = $assignment->getAttribute('restricted_to_id');

            if ($contextType === null && $contextId === null) {
                $roleKeys[] = self::stringable($assignment->getAttribute('role_id'));

                continue;
            }

            $usable = $entity instanceof Model
                && is_string($contextType)
                && (is_int($contextId) || is_string($contextId))
                && $this->context->belongsToContext($entity, $contextType, $contextId);

            if ($usable) {
                $roleKeys[] = self::stringable($assignment->getAttribute('role_id'));
            }
        }

        $viaRole = $grants->first(
            fn (Model $grant): bool => $grant->getAttribute('entity_type') === $roleMorph
                && in_array($this->stringable($grant->getAttribute('entity_id')), $roleKeys, true),
        );

        if ($viaRole !== null) {
            $role = $this->context->roleClass()::query()
                ->withoutGlobalScope(TenantScope::class)
                ->whereKey($viaRole->getAttribute('entity_id'))
                ->first();

            return [$forbidden ? Cause::ForbiddenViaRole : Cause::GrantedViaRole, $role];
        }

        return [$forbidden ? Cause::ForbiddenToEveryone : Cause::GrantedToEveryone, null];
    }

    private function stringable(mixed $value): string
    {
        return is_int($value) || is_string($value) ? (string) $value : '';
    }
}
