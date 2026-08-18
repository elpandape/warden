<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Database\Factories;

use ElPandaPe\Warden\Models\Permission;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Permission>
 */
class PermissionFactory extends Factory
{
    /**
     * @return array<model-property<Permission>, mixed>
     */
    public function definition(): array
    {
        /** @var array<model-property<Permission>, mixed> $definition */
        $definition = [
            'name' => 'permission-'.Str::lower(Str::random(8)),
        ];

        return $definition;
    }

    public function modelName(): string
    {
        return Permission::class;
    }
}
