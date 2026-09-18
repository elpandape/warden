<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Checks\Explain;

use BackedEnum;
use ElPandaPe\Warden\Checks\Resolvers\DatabaseResolver;
use ElPandaPe\Warden\Checks\Verdict;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Support\Expiry;
use ElPandaPe\Warden\Support\Name;
use ElPandaPe\Warden\Tenancy\TenantScope;
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
        // One read of assigned_roles for the whole diagnosis: the resolver walks
        // the closure from it, and the blame step below reuses that walk.
        $assignments = DatabaseResolver::readAssignments($this->context, $authority);
        $resolver = new DatabaseResolver($this->context, $assignments);

        $verdict = $resolver->resolve($authority, Name::of($permission), $entity);

        if ($verdict->isAbstained()) {
            $applicable = ! is_string($entity)
                || $entity === '*'
                || is_subclass_of($entity, Model::class);

            if (! $applicable) {
                return new AuthorizationExplanation($verdict, Cause::NotApplicable);
            }

            $rejected = $verdict->permission;

            return new AuthorizationExplanation(
                $verdict,
                $rejected instanceof Model ? Cause::ConditionsNotMet : Cause::NoMatchingGrant,
                $rejected,
            );
        }

        // Re-reading it by key would return the row it already matched.
        $decisive = $verdict->permission;

        [$cause, $role] = $this->source($authority, $verdict, $entity, $resolver->closure() ?? []);

        return new AuthorizationExplanation($verdict, $cause, $decisive, $role);
    }

    /**
     * How the decisive permission reaches the authority: directly, through a
     * role, or as an everyone-grant — reported most-specific first.
     *
     * @param  array<int|string, list<array{string|null, int|string|null, int|null}>>  $closure
     * @return array{0: Cause, 1: Model|null}
     */
    private function source(Model $authority, Verdict $verdict, Model|string|null $entity, array $closure): array
    {
        $forbidden = $verdict->isForbidden();
        $roleMorph = (new ($this->context->roleClass()))->getMorphClass();

        $grants = $this->context->grantClass()::query()
            ->where('permission_id', $verdict->permissionKey)
            ->where('forbidden', $forbidden)
            ->tap(Expiry::live(...))
            ->get();

        $direct = $grants->first(
            fn (Model $grant): bool => $grant->getAttribute('entity_type') === $authority->getMorphClass()
                && $this->stringable($grant->getAttribute('entity_id')) === $this->stringable($authority->getKey()),
        );

        if ($direct !== null) {
            return [$forbidden ? Cause::ForbiddenDirectly : Cause::GrantedDirectly, null];
        }

        // Only the roles the resolver itself used for this check, nested ones
        // included: a restricted role outside its context must not be blamed.
        $roleKeys = [];

        foreach ($closure as $roleKey => $restrictions) {
            foreach ($restrictions as [$contextType, $contextId]) {
                if ($contextType === null && $contextId === null) {
                    $roleKeys[] = $this->stringable($roleKey);

                    continue;
                }

                $usable = $entity instanceof Model
                    && $contextType !== null
                    && $contextId !== null
                    && $this->context->belongsToContext($entity, $contextType, $contextId);

                if ($usable) {
                    $roleKeys[] = $this->stringable($roleKey);
                }
            }
        }

        // The closure lists the roles held directly first: blame the nearest.
        $viaRole = null;

        foreach ($roleKeys as $roleKey) {
            $viaRole ??= $grants->first(
                fn (Model $grant): bool => $grant->getAttribute('entity_type') === $roleMorph
                    && $this->stringable($grant->getAttribute('entity_id')) === $roleKey,
            );
        }

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
