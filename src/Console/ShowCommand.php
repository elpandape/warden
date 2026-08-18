<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Console;

use ElPandaPe\Warden\Context;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

final class ShowCommand extends Command
{
    protected $signature = 'warden:show {authority? : Inspect one authority, as Class:id (e.g. "App\\Models\\User:1")}';

    protected $description = 'Show the roles and permissions catalog, or one authority\'s access';

    public function handle(): int
    {
        $authority = $this->argument('authority');

        return is_string($authority) && $authority !== ''
            ? $this->showAuthority($authority)
            : $this->showCatalog();
    }

    private function showCatalog(): int
    {
        $context = Context::resolve();

        $roles = $context->roleClass()::query()->withoutGlobalScopes()->get()
            ->map(fn (Model $role): array => [
                $role->getAttribute('name'),
                $role->getAttribute('title'),
                $role->getAttribute('scope') ?? 'global',
            ])->all();

        $permissions = $context->permissionClass()::query()->withoutGlobalScopes()->get()
            ->map(fn (Model $permission): array => [
                $permission->getAttribute('name'),
                $permission->getAttribute('entity_type') ?? '—',
                $permission->getAttribute('entity_id') ?? '—',
                $permission->getAttribute('only_owned') ? 'yes' : 'no',
                $permission->getAttribute('options') === null ? 'no' : 'yes',
                $permission->getAttribute('scope') ?? 'global',
            ])->all();

        $this->components->info('Roles');
        $this->table(['Name', 'Title', 'Scope'], $roles);
        $this->components->info('Permissions');
        $this->table(['Name', 'Entity type', 'Entity id', 'Owned-only', 'Constrained', 'Scope'], $permissions);

        return self::SUCCESS;
    }

    private function showAuthority(string $reference): int
    {
        $context = Context::resolve();
        [$class, $key] = array_pad(explode(':', $reference, 2), 2, null);

        if ($class === null || $key === null || ! is_subclass_of($class, Model::class)) {
            $this->components->error('Pass the authority as Class:id, e.g. "App\Models\User:1".');

            return self::FAILURE;
        }

        $authority = $class::query()->find($key);

        if (! $authority instanceof Model) {
            $this->components->error("No [{$class}] found with key [{$key}].");

            return self::FAILURE;
        }

        $assignments = $context->assignedRoleClass()::query()
            ->withoutGlobalScopes()
            ->where('entity_type', $authority->getMorphClass())
            ->where('entity_id', $authority->getKey())
            ->get();

        $roleNames = $context->roleClass()::query()->withoutGlobalScopes()
            ->whereKey($assignments->pluck('role_id')->all())
            ->get()
            ->keyBy(fn (Model $role): string => $this->display($role->getKey()));

        $this->components->info("Roles held by {$class}:{$key}");
        $this->table(
            ['Role', 'Restricted to', 'Scope'],
            $assignments->map(function (Model $assignment) use ($roleNames): array {
                $role = $roleNames->get($this->display($assignment->getAttribute('role_id')));
                $type = $assignment->getAttribute('restricted_to_type');

                return [
                    $role?->getAttribute('name') ?? '?',
                    $type === null
                        ? '—'
                        : $this->display($type).':'.$this->display($assignment->getAttribute('restricted_to_id')),
                    $assignment->getAttribute('scope') ?? 'global',
                ];
            })->all(),
        );

        $grants = $context->grantClass()::query()
            ->withoutGlobalScopes()
            ->where('entity_type', $authority->getMorphClass())
            ->where('entity_id', $authority->getKey())
            ->get();

        $permissionNames = $context->permissionClass()::query()->withoutGlobalScopes()
            ->whereKey($grants->pluck('permission_id')->all())
            ->get()
            ->keyBy(fn (Model $permission): string => $this->display($permission->getKey()));

        $this->components->info('Direct grants');
        $this->table(
            ['Permission', 'Kind', 'Scope'],
            $grants->map(function (Model $grant) use ($permissionNames): array {
                $permission = $permissionNames->get($this->display($grant->getAttribute('permission_id')));

                return [
                    $permission?->getAttribute('name') ?? '?',
                    $grant->getAttribute('forbidden') ? 'forbidden' : 'granted',
                    $grant->getAttribute('scope') ?? 'global',
                ];
            })->all(),
        );

        return self::SUCCESS;
    }

    private function display(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '—';
    }
}
