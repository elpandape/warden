<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Database\Concerns;

use ElPandaPe\Bouncer\Context;
use ElPandaPe\Bouncer\Database\Grant;
use ElPandaPe\Bouncer\Database\Titles\PermissionTitle;
use ElPandaPe\Bouncer\Support\Config;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait IsPermission
{
    use ResolvesContext;

    /**
     * @return MorphToMany<Model, $this, Grant>
     */
    public function roles(): MorphToMany
    {
        $context = Context::resolve();

        $role = $context->modelClass('role');
        /** @var class-string<Grant> $grant */
        $grant = $context->modelClass('grant');

        $relation = $this
            ->morphedByMany($role, 'entity', $context->table('grants'), 'permission_id')
            ->using($grant)
            ->withPivot(['forbidden', 'scope']);

        return Config::pivotTimestamps() ? $relation->withTimestamps() : $relation;
    }

    protected static function bootIsPermission(): void
    {
        static::creating(function (Model $permission): void {
            if (Config::titlesAutogenerate() && $permission->getAttribute('title') === null) {
                $name = $permission->getAttribute('name');
                $type = $permission->getAttribute('entity_type');
                $entityId = $permission->getAttribute('entity_id');

                $permission->setAttribute('title', PermissionTitle::generate(
                    name: is_string($name) ? $name : '',
                    entityType: is_string($type) ? $type : null,
                    entityId: is_int($entityId) || is_string($entityId) ? $entityId : null,
                    onlyOwned: (bool) $permission->getAttribute('only_owned'),
                ));
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // No cast on entity_id: it must carry UUID/ULID keys untouched.
        return [
            'only_owned' => 'boolean',
            'options' => 'array',
        ];
    }

    protected function contextTableKey(): string
    {
        return 'permissions';
    }
}
