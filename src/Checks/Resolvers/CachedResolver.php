<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Checks\Resolvers;

use ElPandaPe\Bouncer\Checks\Verdict;
use ElPandaPe\Bouncer\Constraints\ConstraintSerializer;
use ElPandaPe\Bouncer\Constraints\Group;
use ElPandaPe\Bouncer\Context;
use ElPandaPe\Bouncer\Contracts\Resolver;
use ElPandaPe\Bouncer\Models\Grant;
use ElPandaPe\Bouncer\Support\Config;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Caches one minimal payload per authority — every grant tuple that could
 * ever apply to it — and answers checks by matching in memory with the same
 * semantics as the database engine. The payload is versioned so v0.8 fields
 * (constraints, role restrictions) extend it without breaking old entries.
 *
 * @phpstan-type GrantTuple array{key: int|string, name: string, entity_type: string|null, entity_id: int|string|null, only_owned: bool, forbidden: bool, options: array<array-key, mixed>|null, restricted_to_type: string|null, restricted_to_id: int|string|null}
 */
final class CachedResolver implements Resolver
{
    private const int PAYLOAD_VERSION = 2;

    private const int LOCK_SECONDS = 10;

    private const int MEMO_LIMIT = 256;

    /** @var array<string, list<GrantTuple>> */
    private array $memo = [];

    public function __construct(
        private readonly Resolver $inner,
        private readonly Context $context,
        private readonly CacheKeyVersioner $versions,
        private readonly int $lockWaitSeconds = 5,
    ) {}

    public function resolve(
        Model $authority,
        string $permission,
        Model|string|null $entity = null,
    ): Verdict {
        if (! Config::cacheEnabled()) {
            return $this->inner->resolve($authority, $permission, $entity);
        }

        // A string that is not a model class belongs to app policies: abstain.
        if (is_string($entity) && $entity !== '*' && ! is_subclass_of($entity, Model::class)) {
            return Verdict::abstained();
        }

        // Ownership resolves per check, never from cache: closures see live data.
        $owned = $entity instanceof Model && $this->context->isOwnedBy($authority, $entity);

        $tuples = $this->payload($authority);

        // Forbidden always wins: check it before any grant.
        $forbiddenBy = $this->firstMatch($tuples, $authority, $permission, $entity, $owned, forbidden: true);

        if ($forbiddenBy !== null) {
            return Verdict::forbidden($forbiddenBy);
        }

        $grantedBy = $this->firstMatch($tuples, $authority, $permission, $entity, $owned, forbidden: false);

        return $grantedBy === null ? Verdict::abstained() : Verdict::granted($grantedBy);
    }

    /**
     * Drop the cached payload for one authority under the current read filter.
     */
    public function forgetFor(Model $authority): void
    {
        $key = $this->key($authority);

        unset($this->memo[$key]);
        $this->versions->store()->forget($key);
    }

    /**
     * @return list<GrantTuple>
     */
    private function payload(Model $authority): array
    {
        $key = $this->key($authority);

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        // Version bumps change the key, so a bounded reset is the only upkeep
        // long-lived workers need.
        if (count($this->memo) >= self::MEMO_LIMIT) {
            $this->memo = [];
        }

        $store = $this->versions->store();
        $cached = $this->validPayload($store->get($key));

        if ($cached !== null) {
            return $this->memo[$key] = $cached;
        }

        return $this->memo[$key] = $this->buildLocked($store, $key, $authority);
    }

    /**
     * Rebuild under a lock when the store supports one, so a cold key does not
     * stampede the database; on timeout, serving directly beats queueing up.
     *
     * @return list<GrantTuple>
     */
    private function buildLocked(Repository $store, string $key, Model $authority): array
    {
        $provider = $store->getStore();

        if ($provider instanceof LockProvider) {
            try {
                /** @var list<GrantTuple> $tuples */
                $tuples = $provider->lock("{$key}:lock", self::LOCK_SECONDS)
                    ->block($this->lockWaitSeconds, function () use ($store, $key, $authority): array {
                        // Double-check: the lock holder before us may have built it.
                        $fresh = $this->validPayload($store->get($key));

                        return $fresh ?? $this->buildAndPut($store, $key, $authority);
                    });

                return $tuples;
            } catch (LockTimeoutException) {
                return $this->buildAndPut($store, $key, $authority);
            }
        }

        return $this->buildAndPut($store, $key, $authority);
    }

    /**
     * @return list<GrantTuple>
     */
    private function buildAndPut(Repository $store, string $key, Model $authority): array
    {
        $tuples = $this->build($authority);

        $store->put($key, ['v' => self::PAYLOAD_VERSION, 'grants' => $tuples], Config::cacheTtl());

        return $tuples;
    }

    /**
     * Three queries per cold authority; zero afterwards, whatever the check count.
     *
     * @return list<GrantTuple>
     */
    private function build(Model $authority): array
    {
        $roleMorph = (new ($this->context->roleClass()))->getMorphClass();
        $authorityMorph = $authority->getMorphClass();
        $authorityKey = $authority->getKey();

        // Every assignment, restrictions included: a role granted through a
        // restricted assignment carries that context into its tuples.
        /** @var array<int|string, list<array{string|null, int|string|null}>> $restrictionsByRole */
        $restrictionsByRole = [];

        foreach ($this->context->assignedRoleClass()::query()
            ->where('entity_type', $authorityMorph)
            ->where('entity_id', $authorityKey)
            ->get() as $assignment) {
            $roleKey = $assignment->getAttribute('role_id');

            if (! is_int($roleKey) && ! is_string($roleKey)) {
                continue; // @codeCoverageIgnore
            }

            $contextType = $assignment->getAttribute('restricted_to_type');
            $contextId = $assignment->getAttribute('restricted_to_id');

            $type = is_string($contextType) ? $contextType : null;
            $id = is_int($contextId) || is_string($contextId) ? $contextId : null;

            // A half-written restriction is not "unrestricted": fail closed.
            if (($type === null) !== ($id === null)) {
                continue;
            }

            $restrictionsByRole[$roleKey][] = [$type, $id];
        }

        $roleKeys = array_keys($restrictionsByRole);

        // The grant model's global scope applies the same read filter the
        // database engine uses on its raw subquery.
        $grantRows = $this->context->grantClass()::query()
            ->where(
                /** @param Builder<Grant> $query */
                function (Builder $query) use ($authorityMorph, $authorityKey, $roleMorph, $roleKeys): void {
                    $query
                        ->where(
                            /** @param Builder<Grant> $direct */
                            function (Builder $direct) use ($authorityMorph, $authorityKey): void {
                                $direct->where('entity_type', $authorityMorph)
                                    ->where('entity_id', $authorityKey);
                            },
                        )
                        ->orWhere(
                            /** @param Builder<Grant> $viaRole */
                            function (Builder $viaRole) use ($roleMorph, $roleKeys): void {
                                $viaRole->where('entity_type', $roleMorph)
                                    ->whereIn('entity_id', $roleKeys);
                            },
                        )
                        ->orWhereNull('entity_id');
                },
            )
            ->get();

        // Deduplicate (permission, forbidden, restriction) triples: role
        // grants expand once per assignment so restrictions ride along.
        /** @var array<string, array{int|string, bool, string|null, int|string|null}> $pairs */
        $pairs = [];

        foreach ($grantRows as $grant) {
            $viaRole = $grant->entity_type === $roleMorph
                && $grant->entity_id !== null
                && isset($restrictionsByRole[$grant->entity_id]);

            $restrictions = $viaRole ? $restrictionsByRole[$grant->entity_id] : [[null, null]];

            foreach ($restrictions as [$contextType, $contextId]) {
                $key = implode(':', [
                    (string) $grant->permission_id,
                    $grant->forbidden ? '1' : '0',
                    $contextType ?? '',
                    $contextId === null ? '' : (string) $contextId,
                ]);

                $pairs[$key] = [$grant->permission_id, $grant->forbidden, $contextType, $contextId];
            }
        }

        $permissionKeys = array_values(array_unique(array_column($pairs, 0)));

        // Resolve through Eloquent so the catalog read scope keeps applying.
        $permissions = [];

        foreach ($this->context->permissionClass()::query()->whereKey($permissionKeys)->get() as $permission) {
            $key = $permission->getKey();

            if (is_int($key) || is_string($key)) {
                $permissions[$key] = $permission;
            }
        }

        $tuples = [];

        foreach ($pairs as [$permissionKey, $forbidden, $contextType, $contextId]) {
            $permission = $permissions[$permissionKey] ?? null;

            if ($permission === null) {
                continue;
            }

            $tuples[] = [
                'key' => $permissionKey,
                'name' => $permission->name,
                'entity_type' => $permission->entity_type,
                'entity_id' => $permission->entity_id,
                'only_owned' => $permission->only_owned,
                'forbidden' => $forbidden,
                'options' => $permission->options,
                'restricted_to_type' => $contextType,
                'restricted_to_id' => $contextId,
            ];
        }

        return $tuples;
    }

    /**
     * @return list<GrantTuple>|null
     */
    private function validPayload(mixed $cached): ?array
    {
        if (! is_array($cached) || ($cached['v'] ?? null) !== self::PAYLOAD_VERSION) {
            return null;
        }

        $grants = $cached['grants'] ?? null;

        if (! is_array($grants)) {
            return null;
        }

        /** @var list<GrantTuple> $grants */
        return $grants;
    }

    /**
     * Mirrors the database engine's matching exactly: same wildcard shapes,
     * same ownership gate, same specificity order.
     *
     * @param  list<GrantTuple>  $tuples
     */
    private function firstMatch(
        array $tuples,
        Model $authority,
        string $permission,
        Model|string|null $entity,
        bool $owned,
        bool $forbidden,
    ): int|string|null {
        $candidates = [];

        foreach ($tuples as $tuple) {
            if ($tuple['forbidden'] !== $forbidden) {
                continue;
            }

            if ($tuple['name'] !== $permission && $tuple['name'] !== '*') {
                continue;
            }

            // Ownership-scoped rows only match when the authority owns the entity.
            if (! $owned && $tuple['only_owned']) {
                continue;
            }

            // A restricted assignment only counts in its context (fail-closed
            // without an instance, unless the entity IS the context).
            if ($tuple['restricted_to_type'] !== null && $tuple['restricted_to_id'] !== null) {
                $inContext = $entity instanceof Model && $this->context->belongsToContext(
                    $entity,
                    $tuple['restricted_to_type'],
                    $tuple['restricted_to_id'],
                );

                if (! $inContext) {
                    continue;
                }
            }

            if ($this->matchesEntity($tuple, $entity)) {
                $candidates[] = $tuple;
            }
        }

        usort($candidates, fn (array $a, array $b): int => (($b['entity_id'] !== null) <=> ($a['entity_id'] !== null))
            ?: (($b['entity_type'] !== null) <=> ($a['entity_type'] !== null)));

        foreach ($candidates as $candidate) {
            if ($this->passesConstraints($candidate, $entity, $authority, $forbidden)) {
                return $candidate['key'];
            }
        }

        return null;
    }

    /**
     * Constraints condition the instance: tuples carrying them never match
     * instance-less checks, and corrupt shapes fail closed.
     *
     * @param  GrantTuple  $tuple
     */
    private function passesConstraints(array $tuple, Model|string|null $entity, Model $authority, bool $forbidden): bool
    {
        if ($tuple['options'] === null) {
            return true;
        }

        $group = ConstraintSerializer::deserialize($tuple['options']);

        if (! $group instanceof Group) {
            // Undecidable constraints fail closed in each pass's safe
            // direction: a grant must not widen, a forbid must not lift.
            return $forbidden;
        }

        if (! $entity instanceof Model) {
            return false;
        }

        return $group->passes($entity, $authority);
    }

    /**
     * @param  GrantTuple  $tuple
     */
    private function matchesEntity(array $tuple, Model|string|null $entity): bool
    {
        $type = $tuple['entity_type'];
        $id = $tuple['entity_id'];

        if ($entity === null) {
            // A simple check: named permissions match the simple shape only;
            // the '*' name additionally matches the global wildcard shape.
            return $type === null || ($tuple['name'] === '*' && $type === '*');
        }

        if ($entity === '*') {
            return $type === '*';
        }

        if (is_string($entity)) {
            // resolve() already abstained on non-class strings.
            assert(is_subclass_of($entity, Model::class));

            return $type === '*' || ($type === (new $entity)->getMorphClass() && $id === null);
        }

        if ($type === '*') {
            return true;
        }

        if ($type !== $entity->getMorphClass()) {
            return false;
        }

        if ($id === null) {
            return true;
        }

        $key = $entity->getKey();

        // String-cast comparison: databases match int and string keys loosely.
        return (is_int($key) || is_string($key)) && (string) $id === (string) $key;
    }

    private function key(Model $authority): string
    {
        $key = $authority->getKey();

        return implode(':', [
            Config::cachePrefix(),
            'p'.self::PAYLOAD_VERSION,
            $this->versions->segment(),
            $authority->getMorphClass(),
            is_int($key) || is_string($key) ? (string) $key : '',
        ]);
    }
}
