<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Events\Concerns;

use ElPandaPe\Warden\Events\AssignmentChange;
use ElPandaPe\Warden\Events\AssignmentRemoval;
use ElPandaPe\Warden\Events\GrantChange;
use ElPandaPe\Warden\Events\GrantRemoval;
use ElPandaPe\Warden\Events\SyncResult;
use Illuminate\Contracts\Queue\QueueableCollection;
use Illuminate\Contracts\Queue\QueueableEntity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use SplObjectStorage;

/**
 * Queues the rows a write names by value, without the relations loaded on
 * them, so a payload holds no column of a related row. Synchronous listeners
 * keep the instances the write used. A row with relations loaded is copied once
 * per payload, so a list and its entries still share one instance once a job
 * restores them; a row with none goes as it is. Top-level models still go
 * through SerializesModels, which the host event uses: they travel as
 * identifiers, and its __unserialize() restores them.
 */
trait QueuesWithoutRelations
{
    /**
     * @return array<mixed>
     */
    public function __serialize(): array
    {
        /** @var SplObjectStorage<Model, Model> $copies */
        $copies = new SplObjectStorage;

        return array_map(
            fn (mixed $value): mixed => $value instanceof QueueableEntity || $value instanceof QueueableCollection
                ? $this->getSerializedPropertyValue($value)
                : $this->withoutRelationsIn($value, $copies),
            get_object_vars($this),
        );
    }

    /**
     * @param  SplObjectStorage<Model, Model>  $copies
     */
    private function withoutRelationsIn(mixed $value, SplObjectStorage $copies): mixed
    {
        return match (true) {
            $value instanceof Model => $this->modelWithoutRelations($value, $copies),
            $value instanceof Collection => $value->map(fn (mixed $item): mixed => $this->withoutRelationsIn($item, $copies)),
            is_array($value) => array_map(fn (mixed $item): mixed => $this->withoutRelationsIn($item, $copies), $value),
            $value instanceof SyncResult => new SyncResult(
                $this->modelsWithoutRelations($value->attached, $copies),
                $this->modelsWithoutRelations($value->detached, $copies),
                $this->modelsWithoutRelations($value->kept, $copies),
            ),
            $value instanceof GrantChange => new GrantChange(
                $this->modelWithoutRelations($value->permission, $copies),
                $value->created,
                $value->expiresAt,
                $value->previousExpiresAt,
            ),
            $value instanceof AssignmentChange => new AssignmentChange(
                $this->modelWithoutRelations($value->role, $copies),
                $value->created,
                $value->expiresAt,
                $value->previousExpiresAt,
            ),
            $value instanceof GrantRemoval => new GrantRemoval(
                $this->modelWithoutRelations($value->permission, $copies),
                $value->expiresAt,
            ),
            $value instanceof AssignmentRemoval => new AssignmentRemoval(
                $this->modelWithoutRelations($value->role, $copies),
                $value->restrictedTo instanceof Model ? $this->modelWithoutRelations($value->restrictedTo, $copies) : null,
                $value->expiresAt,
            ),
            default => $value,
        };
    }

    /**
     * @param  Collection<int, Model>  $models
     * @param  SplObjectStorage<Model, Model>  $copies
     * @return Collection<int, Model>
     */
    private function modelsWithoutRelations(Collection $models, SplObjectStorage $copies): Collection
    {
        return $models->map(fn (Model $model): Model => $this->modelWithoutRelations($model, $copies));
    }

    /**
     * @param  SplObjectStorage<Model, Model>  $copies
     */
    private function modelWithoutRelations(Model $model, SplObjectStorage $copies): Model
    {
        if ($model->getRelations() === []) {
            return $model;
        }

        return $copies[$model] ??= $model->withoutRelations();
    }
}
