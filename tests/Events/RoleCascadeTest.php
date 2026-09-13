<?php

declare(strict_types=1);

use ElPandaPe\Warden\Checks\Resolvers\CacheInvalidations;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Events\RoleDeleted;
use ElPandaPe\Warden\Events\RoleRetracted;
use ElPandaPe\Warden\Models\AssignedRole;
use ElPandaPe\Warden\Models\Grant;
use ElPandaPe\Warden\Models\Permission;
use ElPandaPe\Warden\Models\Role;
use ElPandaPe\Warden\Support\Snapshots\PermissionSnapshot;
use ElPandaPe\Warden\Support\Snapshots\RoleSnapshot;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\SoftDeletingRole;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

it('carries what the deleted role held, as it was', function (): void {
    $this->warden->allow('editor')->until(Carbon::parse('2030-01-01 00:00:00'))->to('view', Account::class);
    $this->warden->forbid('editor')->to('delete');
    nestRole('auditor', 'editor');

    $view = Permission::query()->where('name', 'view')->sole();
    $delete = Permission::query()->where('name', 'delete')->sole();
    $auditor = Role::query()->where('name', 'auditor')->sole();

    Event::fake([RoleDeleted::class]);

    Role::query()->where('name', 'editor')->sole()->delete();

    $event = Event::dispatched(RoleDeleted::class)->sole()[0];
    [$viewing, $deleting] = $event->heldGrants;

    expect($event->heldGrants)->toHaveCount(2)
        ->and(Arr::except($viewing, 'expires_at'))->toBe([
            'permission' => PermissionSnapshot::of($view),
            'forbidden' => false,
            'scope' => null,
        ])
        ->and($viewing['expires_at']?->toDateTimeString())->toBe('2030-01-01 00:00:00')
        ->and($deleting)->toBe([
            'permission' => PermissionSnapshot::of($delete),
            'forbidden' => true,
            'scope' => null,
            'expires_at' => null,
        ])
        ->and($event->heldRoles)->toBe([[
            'role' => RoleSnapshot::of($auditor),
            'scope' => null,
            'restricted_to_type' => null,
            'restricted_to_id' => null,
            'expires_at' => null,
        ]]);
});

it('sweeps without reading or announcing anything when events are disabled', function (): void {
    config()->set('warden.events_enabled', false);
    $this->warden->allow('editor')->to('publish');
    nestRole('auditor', 'editor');
    $this->warden->assign('editor')->to($this->ana);
    $editor = Role::query()->where('name', 'editor')->sole();

    Event::fake([RoleDeleted::class, RoleRetracted::class]);
    DB::enableQueryLog();

    $editor->delete();

    $announcing = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $query): bool => str_contains($query, 'expires_at'));

    expect($announcing->all())->toBeEmpty()
        ->and(Grant::query()->withoutGlobalScopes()->where('entity_type', $editor->getMorphClass())->exists())->toBeFalse()
        ->and(AssignedRole::query()->withoutGlobalScopes()->exists())->toBeFalse();
    Event::assertNotDispatched(RoleDeleted::class);
    Event::assertNotDispatched(RoleRetracted::class);
});

it('keeps no photo of a role the deprecated markCascade settled', function (): void {
    $this->warden->allow('editor')->to('publish');
    $editor = Role::query()->where('name', 'editor')->sole();
    $invalidations = app(CacheInvalidations::class);

    $invalidations->prepareCascade($editor);
    $editor->deleteQuietly();
    $invalidations->markCascade($editor);

    expect($invalidations->pullHeld($editor))->toBe(['grants' => [], 'roles' => []]);
});
