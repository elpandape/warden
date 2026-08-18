<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Models;

use ElPandaPe\Warden\Models\Concerns\ResolvesContext;
use ElPandaPe\Warden\Support\Config;
use ElPandaPe\Warden\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\MorphPivot;

/**
 * @property int $id
 * @property int|string $permission_id
 * @property string|null $entity_type
 * @property int|string|null $entity_id
 * @property bool $forbidden
 * @property int|null $scope
 */
class Grant extends MorphPivot
{
    use BelongsToTenant;
    use ResolvesContext;

    public $incrementing = true;

    public function usesTimestamps(): bool
    {
        return Config::pivotTimestamps();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'forbidden' => 'boolean',
        ];
    }

    protected function contextTableKey(): string
    {
        return 'grants';
    }
}
