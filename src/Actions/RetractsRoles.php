<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Actions;

use BackedEnum;
use ElPandaPe\Warden\Actions\Concerns\NormalizesRoles;
use ElPandaPe\Warden\Actions\Concerns\RemovesByKey;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Events\AssignmentRemoval;
use ElPandaPe\Warden\Events\Concerns\DispatchesEvents;
use ElPandaPe\Warden\Events\RetractingRole;
use ElPandaPe\Warden\Events\RoleRetracted;
use ElPandaPe\Warden\Exceptions\ConfigurationException;
use ElPandaPe\Warden\Models\AssignedRole;
use ElPandaPe\Warden\Support\Announcer;
use ElPandaPe\Warden\Support\Expiry;
use ElPandaPe\Warden\Support\MorphHydrator;
use ElPandaPe\Warden\Tenancy\Tenancy;
use ElPandaPe\Warden\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;

class RetractsRoles
{
    use Concerns\BumpsCacheVersion;
    use DispatchesEvents;
    use NormalizesRoles;
    use RemovesByKey;

    /** @var list<string|Model> */
    private readonly array $roles;

    private ?Model $restrictedTo = null;

    private bool $retracted = false;

    private int $retractedCount = 0;

    /**
     * @param  string|array<int, mixed>|Model|BackedEnum  $roles
     */
    public function __construct(string|array|Model|BackedEnum $roles)
    {
        $this->roles = $this->normalizeRoles($roles);
    }

    /**
     * Retract only the assignment restricted to this context. Without on(),
     * every assignment of the role goes, restricted ones included.
     */
    public function on(Model $context): static
    {
        if ($this->retracted) {
            throw new ConfigurationException('Call on() before from(): retractions execute immediately.');
        }

        // The same contract the assigning side keeps: an unsaved context cannot
        // match a stored restriction, so accepting it only deletes nothing.
        if (! $context->exists) {
            throw new ConfigurationException('The restriction context must be a saved model.');
        }

        $key = $context->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw new ConfigurationException('The restriction context must have a usable key.');
        }

        $this->restrictedTo = $context;

        return $this;
    }

    /**
     * Assignment rows this retract actually deleted.
     *
     * Reads answer "global or this tenant"; deletes target this tenant only, so
     * a count of zero can mean the authority still holds the role globally, and
     * a non-zero count does not promise the role is gone everywhere.
     */
    public function retractedCount(): int
    {
        return $this->retractedCount;
    }

    /**
     * @param  Model|array<int, mixed>  $authorities
     */
    public function from(Model|array $authorities): static
    {
        return $this->asOneWrite(function () use ($authorities): static {
            $this->retracted = true;
            $this->retractedCount = 0;

            $roles = $this->requestedRoles();

            // Deletes target the exact write scope: global assignments survive tenant retracts.
            $scope = app(Tenancy::class)->writeScope();

            $targets = $this->normalizeAuthorities($authorities, removing: true);

            if (! $this->eventPermits(fn (): RetractingRole => new RetractingRole($this->roles, $targets, $scope, $this->restrictedTo))) {
                return $this;
            }

            // Every authority loses its rows before any listener runs, so one
            // that throws cannot leave the next authority holding the role.
            $lost = [];

            foreach ($targets as $authority) {
                $deleted = $this->deleteByKey($this->assignmentsOf($authority, array_keys($roles), $scope));
                $this->retractedCount += count($deleted);

                if ($deleted !== []) {
                    $lost[] = [$authority, $deleted];
                }
            }

            if ($lost === []) {
                return $this;
            }

            $this->bumpCacheVersion($scope);

            // Naming the contexts reads rows: only for events that will go out.
            if ($this->announces()) {
                $this->announceRemovals($lost, $roles, $scope);
            }

            return $this;
        });
    }

    /**
     * @return array<int|string, Model>
     */
    private function requestedRoles(): array
    {
        $roleClass = Context::resolve()->roleClass();
        $found = [];
        $names = [];

        foreach ($this->roles as $role) {
            if ($role instanceof Model) {
                $found[] = $this->assertModelOf($role, $roleClass, 'role');
            } else {
                $names[] = $role;
            }
        }

        if ($names !== []) {
            foreach ($roleClass::query()->whereIn('name', $names)->get() as $role) {
                $found[] = $role;
            }
        }

        return $this->inRequestOrder($this->roles, $found);
    }

    /**
     * @param  list<int|string>  $roleKeys
     * @return iterable<int, AssignedRole>
     */
    private function assignmentsOf(Model $authority, array $roleKeys, int|string|null $scope): iterable
    {
        $query = Context::resolve()->assignedRoleClass()::query()->withoutGlobalScope(TenantScope::class);
        $key = $query->getModel()->getKeyName();

        $query->whereIn('role_id', $roleKeys)
            ->where('entity_type', $authority->getMorphClass())
            ->where('entity_id', $authority->getKey())
            ->where('scope', $scope);

        if ($this->restrictedTo instanceof Model) {
            $query->where('restricted_to_type', $this->restrictedTo->getMorphClass())
                ->where('restricted_to_id', $this->restrictedTo->getKey());
        }

        return $query->orderBy($key)->get([$key, 'role_id', 'restricted_to_type', 'restricted_to_id', 'expires_at']);
    }

    /**
     * @param  list<array{Model, list<AssignedRole>}>  $lost
     * @param  array<int|string, Model>  $roles
     */
    private function announceRemovals(array $lost, array $roles, int|string|null $scope): void
    {
        // An entry travels by value: the relations the caller loaded stay behind.
        $named = $this->restrictedTo?->withoutRelations();
        $contexts = $named instanceof Model ? [] : MorphHydrator::many($this->restrictionsOf($lost));
        $actor = Announcer::actorOnce();

        foreach ($lost as [$authority, $rows]) {
            $assignments = $this->removals($rows, $roles, $named, $contexts);

            if ($assignments === []) {
                continue;
            }

            $this->dispatchWardenEvent(fn (): RoleRetracted => new RoleRetracted(
                $authority,
                collect($assignments)->map(fn (AssignmentRemoval $assignment): Model => $assignment->role)->uniqueStrict()->values(),
                $scope,
                $this->restrictedTo,
                actor: $actor(),
                assignments: $assignments,
            ));
        }
    }

    /**
     * @param  list<array{Model, list<AssignedRole>}>  $lost
     * @return list<array{string, int|string}>
     */
    private function restrictionsOf(array $lost): array
    {
        $pairs = [];

        foreach ($lost as [, $rows]) {
            foreach ($rows as $row) {
                if ($row->restricted_to_type !== null && $row->restricted_to_id !== null) {
                    $pairs[] = [$row->restricted_to_type, $row->restricted_to_id];
                }
            }
        }

        return $pairs;
    }

    /**
     * @param  list<AssignedRole>  $rows
     * @param  array<int|string, Model>  $roles
     * @param  array<string, Model>  $contexts
     * @return list<AssignmentRemoval>
     */
    private function removals(array $rows, array $roles, ?Model $named, array $contexts): array
    {
        $byRole = [];

        foreach ($rows as $row) {
            $byRole[$row->role_id][] = $row;
        }

        $removals = [];

        foreach ($roles as $key => $role) {
            foreach ($byRole[$key] ?? [] as $row) {
                $restricted = $row->restricted_to_type !== null || $row->restricted_to_id !== null;
                $context = $named ?? $this->contextOf($row, $contexts);

                // Null would read as unrestricted: a restriction nobody can name gets no entry.
                if ($restricted && ! $context instanceof Model) {
                    continue;
                }

                $removals[] = new AssignmentRemoval($role, $context, Expiry::of($row));
            }
        }

        return $removals;
    }

    /**
     * @param  AssignedRole  $row
     * @param  array<string, Model>  $contexts
     */
    private function contextOf(Model $row, array $contexts): ?Model
    {
        $type = $row->restricted_to_type;
        $id = $row->restricted_to_id;

        if ($type === null || $id === null) {
            return null;
        }

        return $contexts[MorphHydrator::key($type, $id)] ?? MorphHydrator::standIn($type, $id);
    }
}
