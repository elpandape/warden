<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Database;

use ElPandaPe\Bouncer\Database\Concerns\ResolvesContext;
use ElPandaPe\Bouncer\Support\Config;
use Illuminate\Database\Eloquent\Relations\MorphPivot;

/**
 * @property int $id
 * @property int|string $role_id
 * @property string $entity_type
 * @property int|string $entity_id
 * @property string|null $restricted_to_type
 * @property int|string|null $restricted_to_id
 * @property int|null $scope
 */
class AssignedRole extends MorphPivot
{
    use ResolvesContext;

    public $incrementing = true;

    public function usesTimestamps(): bool
    {
        return Config::pivotTimestamps();
    }

    protected function contextTableKey(): string
    {
        return 'assigned_roles';
    }
}
