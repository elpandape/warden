<?php

declare(strict_types=1);

use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Events\RoleDeleted;
use ElPandaPe\Warden\Events\RoleRetracted;
use ElPandaPe\Warden\Models\AssignedRole;
use ElPandaPe\Warden\Models\Grant;
use ElPandaPe\Warden\Models\Role;
use ElPandaPe\Warden\Tests\Fixtures\SoftDeletingRole;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;

use function ElPandaPe\Warden\Tests\Database\addSoftDeletesToRoles;
use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;
use function ElPandaPe\Warden\Tests\Database\withForeignKeys;
use function ElPandaPe\Warden\Tests\nestRole;

beforeEach(function (): void {
    migrateWardenTables();
    withForeignKeys();

    $this->warden = app(Warden::class);
    $this->ana = User::query()->create(['name' => 'Ana']);
    $this->retractions = fn (): Collection => Event::dispatched(RoleRetracted::class)
        ->map(fn (array $arguments): RoleRetracted => $arguments[0])
        ->values();
});

it('sweeps the nested edges a deleted role held', function (): void {
    nestRole('auditor', 'editor');
    $editor = Role::query()->where('name', 'editor')->sole();

    $editor->delete();

    expect(AssignedRole::query()->withoutGlobalScopes()
        ->where('entity_type', $editor->getMorphClass())
        ->where('entity_id', $editor->getKey())
        ->exists())->toBeFalse();
});

it('leaves a soft-deleted role untouched and announces no loss', function (): void {
    addSoftDeletesToRoles();
    Context::resolve()->setModelClass('role', SoftDeletingRole::class);

    $editor = SoftDeletingRole::query()->create(['name' => 'editor']);
    $this->warden->allow('editor')->to('publish');
    $this->warden->assign('auditor')->to($editor);
    $this->warden->assign('editor')->to($this->ana);

    Event::fake([RoleDeleted::class, RoleRetracted::class]);

    $editor->delete();

    Event::assertDispatched(RoleDeleted::class, fn (RoleDeleted $event): bool => $event->heldGrants === [] && $event->heldRoles === []);
    Event::assertNotDispatched(RoleRetracted::class);
    expect(SoftDeletingRole::withTrashed()->whereKey($editor->getKey())->exists())->toBeTrue()
        ->and(Grant::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(AssignedRole::query()->withoutGlobalScopes()->count())->toBe(2);
});
