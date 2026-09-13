<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Actions;

use BackedEnum;
use DateTimeInterface;
use ElPandaPe\Warden\Actions\Concerns\NormalizesRoles;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Events\AssigningRole;
use ElPandaPe\Warden\Events\AssignmentChange;
use ElPandaPe\Warden\Events\Concerns\DispatchesEvents;
use ElPandaPe\Warden\Events\RoleAssigned;
use ElPandaPe\Warden\Exceptions\ConfigurationException;
use ElPandaPe\Warden\Support\Expiry;
use ElPandaPe\Warden\Tenancy\Tenancy;
use ElPandaPe\Warden\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class AssignsRoles
{
    use Concerns\BumpsCacheVersion;
    use DispatchesEvents;
    use NormalizesRoles;

    /** @var list<string|Model> */
    private readonly array $roles;

    private ?Model $restrictedTo = null;

    private bool $assigned = false;

    private ?DateTimeInterface $expiresAt = null;

    private bool $expiryDeclared = false;

    /**
     * @param  string|array<int, mixed>|Model|BackedEnum  $roles
     */
    public function __construct(string|array|Model|BackedEnum $roles, bool $silentEvents = false)
    {
        $this->roles = $this->normalizeRoles($roles);
        $this->silentEvents = $silentEvents;
    }

    /**
     * End the assignment at a moment: past it, the holder stops holding the
     * role and stops inheriting its grants. Call before to() — writes are
     * immediate — and note the date lives on the assignment, not on the role,
     * so the same role may end on different days for different holders.
     * Pass null to lift an end date a previous write left; not calling until()
     * leaves it alone, which is what keeps sync() from making every
     * assignment it keeps permanent.
     */
    public function until(?DateTimeInterface $moment): static
    {
        if ($this->assigned) {
            throw new ConfigurationException('Call until() before to(): assignments execute immediately.');
        }

        $this->expiresAt = $moment;
        $this->expiryDeclared = true;

        return $this;
    }

    /**
     * Restrict the assignment to one context model: the role's grants only
     * apply to entities belonging to it. Call before to() — writes are
     * immediate, and the same role may repeat across contexts.
     */
    public function on(Model $context): static
    {
        if ($this->assigned) {
            throw new ConfigurationException('Call on() before to(): assignments execute immediately.');
        }

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
     * @param  Model|array<int, mixed>  $authorities
     */
    public function to(Model|array $authorities): static
    {
        return $this->asOneWrite(function () use ($authorities): static {
            $this->assigned = true;

            $assignedRole = Context::resolve()->assignedRoleClass();
            $targets = $this->normalizeAuthorities($authorities);

            // Assignments live in the exact current scope: lookup and creation agree.
            $scope = app(Tenancy::class)->writeScope();

            if (! $this->eventPermits(new AssigningRole($this->roles, $targets, $scope, $this->restrictedTo))) {
                return $this;
            }

            $models = $this->resolveRoleModels($this->roles);
            $entries = [];

            // An assignment model that will not mass assign the date would drop
            // it silently, or throw: assignmentChange() then writes it on its own.
            $insertsExpiry = $this->expiryDeclared && (new $assignedRole)->isFillable('expires_at');

            foreach ($models as $role) {
                $roleKey = $this->modelKey($role);

                foreach ($targets as $index => $authority) {
                    $assignment = $assignedRole::query()->withoutGlobalScope(TenantScope::class)->firstOrCreate([
                        'role_id' => $roleKey,
                        'entity_type' => $authority->getMorphClass(),
                        'entity_id' => $authority->getKey(),
                        'restricted_to_type' => $this->restrictedTo?->getMorphClass(),
                        'restricted_to_id' => $this->restrictedTo?->getKey(),
                        'scope' => $scope,
                    ], $insertsExpiry ? ['expires_at' => $this->expiresAt] : []);

                    $entry = $this->assignmentChange($assignment, $role);

                    if ($entry instanceof AssignmentChange) {
                        $entries[$index][] = $entry;
                    }
                }
            }

            // A write that wrote nothing announces nothing, as removals already do.
            if ($entries === []) {
                return $this;
            }

            $this->bumpCacheVersion($scope);

            $actor = $this->actor();

            // Each authority hears only what its own rows became, in the order asked.
            foreach ($targets as $index => $authority) {
                if (! isset($entries[$index])) {
                    continue;
                }

                $this->dispatchWardenEvent(new RoleAssigned(
                    $authority,
                    new Collection(array_map(fn (AssignmentChange $entry): Model => $entry->role, $entries[$index])),
                    $scope,
                    $this->restrictedTo,
                    actor: $actor,
                    assignments: $entries[$index],
                ));
            }

            return $this;
        });
    }

    private function assignmentChange(Model $assignment, Model $role): ?AssignmentChange
    {
        if ($assignment->wasRecentlyCreated) {
            if ($this->expiryDeclared) {
                Expiry::apply($assignment, $this->expiresAt);
            }

            return new AssignmentChange(role: $role, created: true, expiresAt: Expiry::of($assignment), previousExpiresAt: null);
        }

        if (! $this->expiryDeclared) {
            return null;
        }

        $previous = Expiry::of($assignment);

        return Expiry::apply($assignment, $this->expiresAt)
            ? new AssignmentChange(role: $role, created: false, expiresAt: Expiry::of($assignment), previousExpiresAt: $previous)
            : null;
    }
}
