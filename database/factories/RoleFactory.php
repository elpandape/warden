<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Database\Factories;

use ElPandaPe\Warden\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    /**
     * @return array<model-property<Role>, mixed>
     */
    public function definition(): array
    {
        /** @var array<model-property<Role>, mixed> $definition */
        $definition = [
            'name' => 'role-'.Str::lower(Str::random(8)),
        ];

        return $definition;
    }

    public function modelName(): string
    {
        return Role::class;
    }
}
