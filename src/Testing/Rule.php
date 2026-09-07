<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Testing;

use DateTimeInterface;
use ElPandaPe\Warden\Constraints\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One scripted rule, matching the shapes a catalog row can take: an entity
 * type of '*' answers everything, a type without a key answers a whole class,
 * and a type with a key answers one instance.
 *
 * @internal
 */
final class Rule
{
    public ?Model $authority = null;

    public bool $onlyOwned = false;

    public int|string|null $scope = null;

    public ?Builder $constraints = null;

    public ?DateTimeInterface $expiresAt = null;

    private readonly ?string $entityType;

    private readonly int|string|null $entityId;

    public function __construct(
        public readonly string $permission,
        Model|string|null $entity,
        public readonly bool $forbidden,
    ) {
        $this->entityType = $entity instanceof Model ? $entity->getMorphClass() : $entity;
        $this->entityId = $entity instanceof Model ? $this->keyOf($entity) : null;
    }

    /**
     * @param  array{0: 'both'|'null', 1: int|string|null}|null  $filter
     */
    public function answersFor(
        Model $authority,
        string $permission,
        Model|string|null $entity,
        bool $owned,
        ?array $filter,
    ): bool {
        return $this->namedBy($permission)
            && $this->stillLive()
            && $this->heldBy($authority)
            && $this->visibleUnder($filter)
            && ($owned || ! $this->onlyOwned)
            && $this->coversEntity($entity);
    }

    public function conditionsPass(Model|string|null $entity, Model $authority): bool
    {
        if (! $this->constraints instanceof Builder) {
            return true;
        }

        // Conditions read an instance: without one they decide nothing, and a
        // grant must not widen while a forbid must not lift.
        return $entity instanceof Model
            ? $this->constraints->group()->passes($entity, $authority)
            : $this->forbidden;
    }

    /**
     * Instance beats class, class beats the entity-less shape.
     */
    public function specificity(): int
    {
        return match (true) {
            $this->entityId !== null => 2,
            $this->entityType !== null => 1,
            default => 0,
        };
    }

    /**
     * Same exclusive boundary as the engine: a rule stops counting at the
     * instant it names, not a tick later. If the two disagreed here, parity
     * would be a suite that passes while production denies.
     */
    private function stillLive(): bool
    {
        return ! $this->expiresAt instanceof DateTimeInterface
            || $this->expiresAt->getTimestamp() > Carbon::now()->getTimestamp();
    }

    private function namedBy(string $permission): bool
    {
        return $this->permission === $permission || $this->permission === '*';
    }

    private function heldBy(Model $authority): bool
    {
        return ! $this->authority instanceof Model
            || ($this->authority->getMorphClass() === $authority->getMorphClass()
                && $this->authority->getKey() === $authority->getKey());
    }

    /**
     * @param  array{0: 'both'|'null', 1: int|string|null}|null  $filter
     */
    private function visibleUnder(?array $filter): bool
    {
        return match (true) {
            $filter === null => true,
            $filter[0] === 'null' => $this->scope === null,
            default => $this->scope === null || $this->scope === $filter[1],
        };
    }

    private function coversEntity(Model|string|null $entity): bool
    {
        if ($this->entityType === '*') {
            return true;
        }

        if ($entity === null) {
            // Warden's own matrix: a rule with no entity answers instance-less
            // checks only. A fake that is looser than the thing it fakes lets
            // a test pass where production denies.
            return $this->entityType === null;
        }

        if ($this->entityType === null || $entity === '*') {
            return false;
        }

        if (is_string($entity)) {
            // The fake abstained on any string that is not a model class.
            assert(is_subclass_of($entity, Model::class));

            return $this->entityId === null && new $entity()->getMorphClass() === $this->entityType;
        }

        return $entity->getMorphClass() === $this->entityType
            && ($this->entityId === null || $this->entityId === $this->keyOf($entity));
    }

    private function keyOf(Model $entity): int|string|null
    {
        $key = $entity->getKey();

        return is_int($key) || is_string($key) ? $key : null;
    }
}
