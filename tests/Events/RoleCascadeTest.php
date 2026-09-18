<?php

declare(strict_types=1);

use ElPandaPe\Warden\Checks\Resolvers\CacheInvalidations;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Events\AssignmentRemoval;
use ElPandaPe\Warden\Events\RetractingRole;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

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

it('announces a retraction per holder, with the actor, besides the role deletion', function (): void {
    $acme = Account::query()->create(['name' => 'Acme']);
    $admin = User::query()->create(['name' => 'Admin']);
    $this->warden->assign('editor')->to([$this->ana, $acme]);
    $this->actingAs($admin);

    Event::fake([RoleDeleted::class, RoleRetracted::class]);

    Role::query()->where('name', 'editor')->sole()->delete();

    [$first, $second] = ($this->retractions)()->all();

    Event::assertDispatched(RoleDeleted::class, fn (RoleDeleted $event): bool => $event->actor?->is($admin) === true);
    expect(($this->retractions)())->toHaveCount(2)
        ->and($first->authority->is($this->ana))->toBeTrue()
        ->and($second->authority->is($acme))->toBeTrue()
        ->and($first->roles->sole()->getAttribute('name'))->toBe('editor')
        ->and($first->roles->sole()->exists)->toBeFalse()
        ->and($first->scope)->toBeNull()
        ->and($first->restrictedTo)->toBeNull()
        ->and($first->actor?->is($admin))->toBeTrue()
        ->and($second->actor?->is($admin))->toBeTrue()
        ->and($first->assignments)->toHaveCount(1)
        ->and($first->assignments[0]->role->getAttribute('name'))->toBe('editor')
        ->and($first->assignments[0]->restrictedTo)->toBeNull()
        ->and($first->assignments[0]->expiresAt)->toBeNull();
});

it('dispatches the role deletion before the retractions it caused', function (): void {
    $this->warden->assign('editor')->to($this->ana);
    $order = [];

    Event::listen(RoleDeleted::class, function () use (&$order): void {
        $order[] = 'deleted';
    });
    Event::listen(RoleRetracted::class, function () use (&$order): void {
        $order[] = 'retracted';
    });

    Role::query()->where('name', 'editor')->sole()->delete();

    expect($order)->toBe(['deleted', 'retracted']);
});

it('lists every context a holder loses, row by row, in one retraction', function (): void {
    $orgOne = Account::query()->create(['name' => 'Org One']);
    $orgTwo = Account::query()->create(['name' => 'Org Two']);
    $this->warden->assign('editor')->on($orgOne)->to($this->ana);
    $this->warden->assign('editor')->on($orgTwo)->to($this->ana);
    $this->warden->assign('editor')->to($this->ana);

    Event::fake([RoleRetracted::class]);

    Role::query()->where('name', 'editor')->sole()->delete();

    $retraction = ($this->retractions)()->sole();
    $contexts = array_map(
        fn (AssignmentRemoval $removal): mixed => $removal->restrictedTo?->getKey(),
        $retraction->assignments,
    );

    expect($retraction->restrictedTo)->toBeNull()
        ->and($retraction->roles)->toHaveCount(1)
        ->and($contexts)->toBe([$orgOne->getKey(), $orgTwo->getKey(), null]);
});

it('stands a key-only model in for a context whose row is gone', function (): void {
    $org = Account::query()->create(['name' => 'Org']);
    $this->warden->assign('editor')->on($org)->to($this->ana);
    Account::query()->whereKey($org->getKey())->delete();

    Event::fake([RoleRetracted::class]);

    Role::query()->where('name', 'editor')->sole()->delete();

    $context = ($this->retractions)()->sole()->assignments[0]->restrictedTo;

    expect($context)->toBeInstanceOf(Account::class)
        ->and($context?->exists)->toBeFalse()
        ->and($context?->getKey())->toBe($org->getKey());
});

it('announces a nested holder as a role authority', function (): void {
    nestRole('auditor', 'editor');

    Event::fake([RoleRetracted::class]);

    Role::query()->where('name', 'auditor')->sole()->delete();

    $retraction = ($this->retractions)()->sole();

    expect($retraction->authority)->toBeInstanceOf(Role::class)
        ->and($retraction->authority->getAttribute('name'))->toBe('editor')
        ->and($retraction->roles->sole()->getAttribute('name'))->toBe('auditor');
});

it('names a nested holder from another tenant instead of losing it', function (): void {
    Role::query()->create(['name' => 'auditor']);
    $this->warden->tenant()->to(7);
    nestRole('auditor', 'editor');
    $this->warden->tenant()->to(5);

    Event::fake([RoleRetracted::class]);

    Role::query()->where('name', 'auditor')->sole()->delete();

    $retraction = ($this->retractions)()->sole();

    expect($retraction->authority->getAttribute('name'))->toBe('editor')
        ->and($retraction->authority->getAttribute('scope'))->toBe(7)
        ->and($retraction->scope)->toBe(7);
});

it('announces a holder once per scope it held the role in', function (): void {
    Role::query()->create(['name' => 'editor']);
    $this->warden->tenant()->to(5);
    $this->warden->assign('editor')->to($this->ana);
    $this->warden->tenant()->to(7);
    $this->warden->assign('editor')->to($this->ana);
    $this->warden->tenant()->remove();

    Event::fake([RoleRetracted::class]);

    Role::query()->where('name', 'editor')->sole()->delete();

    $retractions = ($this->retractions)();

    expect($retractions->map(fn (RoleRetracted $event): int|string|null => $event->scope)->all())->toBe([5, 7])
        ->and($retractions->every(fn (RoleRetracted $event): bool => $event->authority->is($this->ana)))->toBeTrue();
});

it('announces an assignment that had already expired, with the date it carried', function (): void {
    $luis = User::query()->create(['name' => 'Luis']);
    $this->warden->assign('editor')->until(Carbon::parse('2020-01-01 00:00:00'))->to($this->ana);
    $this->warden->assign('editor')->to($luis);

    Event::fake([RoleRetracted::class]);

    Role::query()->where('name', 'editor')->sole()->delete();

    [$expired, $live] = ($this->retractions)()->all();

    expect(($this->retractions)())->toHaveCount(2)
        ->and($expired->authority->is($this->ana))->toBeTrue()
        ->and($expired->assignments[0]->expiresAt?->toDateTimeString())->toBe('2020-01-01 00:00:00')
        ->and($live->authority->is($luis))->toBeTrue()
        ->and($live->assignments[0]->expiresAt)->toBeNull();
});

it('skips a holder whose row is gone and warns about a type no class maps', function (): void {
    $luis = User::query()->create(['name' => 'Luis']);
    $this->warden->assign('editor')->to($luis);
    $editor = Role::query()->where('name', 'editor')->sole();

    DB::table('assigned_roles')->insert([
        ['role_id' => $editor->getKey(), 'entity_type' => 'nothing.maps.here', 'entity_id' => 1],
        ['role_id' => $editor->getKey(), 'entity_type' => $this->ana->getMorphClass(), 'entity_id' => 999],
    ]);

    Log::spy();
    Event::fake([RoleRetracted::class]);

    $editor->delete();

    expect(($this->retractions)()->sole()->authority->is($luis))->toBeTrue();
    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => $message === 'Warden: no model class maps the morph type [nothing.maps.here], so its rows cannot be named.'
            && $context === ['entity_type' => 'nothing.maps.here', 'ids' => [1]],
    );
});

it('drops a context no class maps instead of reading it as unrestricted', function (): void {
    $luis = User::query()->create(['name' => 'Luis']);
    $this->warden->assign('editor')->to($this->ana);
    $editor = Role::query()->where('name', 'editor')->sole();

    DB::table('assigned_roles')->insert([
        [
            'role_id' => $editor->getKey(),
            'entity_type' => $this->ana->getMorphClass(),
            'entity_id' => $this->ana->getKey(),
            'restricted_to_type' => 'nothing.maps.here',
            'restricted_to_id' => 1,
        ],
        [
            'role_id' => $editor->getKey(),
            'entity_type' => $luis->getMorphClass(),
            'entity_id' => $luis->getKey(),
            'restricted_to_type' => 'nothing.maps.here',
            'restricted_to_id' => 2,
        ],
    ]);

    Log::spy();
    Event::fake([RoleRetracted::class]);

    $editor->delete();

    $retraction = ($this->retractions)()->sole();

    expect($retraction->authority->is($this->ana))->toBeTrue()
        ->and($retraction->assignments)->toHaveCount(1)
        ->and($retraction->assignments[0]->restrictedTo)->toBeNull();
    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => $message === 'Warden: no model class maps the morph type [nothing.maps.here], so its rows cannot be named.'
            && $context === ['entity_type' => 'nothing.maps.here', 'ids' => [1, 2]],
    );
});

it('never reads a half-written restriction as unrestricted', function (): void {
    $editor = Role::query()->create(['name' => 'editor']);

    DB::table('assigned_roles')->insert([
        'role_id' => $editor->getKey(),
        'entity_type' => $this->ana->getMorphClass(),
        'entity_id' => $this->ana->getKey(),
        'restricted_to_type' => (new Account)->getMorphClass(),
        'restricted_to_id' => null,
    ]);

    Event::fake([RoleRetracted::class]);

    $editor->delete();

    Event::assertNotDispatched(RoleRetracted::class);
});

it('announces nothing for a role deleted through the query builder', function (): void {
    $this->warden->assign('editor')->to($this->ana);

    Event::fake([RoleDeleted::class, RoleRetracted::class]);

    Role::query()->where('name', 'editor')->delete();

    Event::assertNotDispatched(RoleDeleted::class);
    Event::assertNotDispatched(RoleRetracted::class);
    expect(AssignedRole::query()->withoutGlobalScopes()->exists())->toBeFalse();
});

it('never asks cancellable listeners before a cascade', function (): void {
    config()->set('warden.cancellable_events', true);
    $this->warden->assign('editor')->to($this->ana);
    $asked = false;

    Event::listen(RetractingRole::class, function () use (&$asked): bool {
        $asked = true;

        return false;
    });

    Role::query()->where('name', 'editor')->sole()->delete();

    expect($asked)->toBeFalse()
        ->and(AssignedRole::query()->withoutGlobalScopes()->exists())->toBeFalse();
});

it('cascades once a soft-deleting role is force-deleted', function (): void {
    addSoftDeletesToRoles();
    Context::resolve()->setModelClass('role', SoftDeletingRole::class);

    $editor = SoftDeletingRole::query()->create(['name' => 'editor']);
    $this->warden->allow('editor')->to('publish');
    $this->warden->assign('editor')->to($this->ana);

    Event::fake([RoleRetracted::class]);

    $editor->forceDelete();

    expect(($this->retractions)()->sole()->authority->is($this->ana))->toBeTrue()
        ->and(Grant::query()->withoutGlobalScopes()->exists())->toBeFalse();
});

it('announces what a trashed role still held once it is force-deleted', function (): void {
    addSoftDeletesToRoles();
    Context::resolve()->setModelClass('role', SoftDeletingRole::class);

    $editor = SoftDeletingRole::query()->create(['name' => 'editor']);
    $this->warden->allow('editor')->to('publish');
    $this->warden->assign('editor')->to($this->ana);

    Event::fake([RoleDeleted::class, RoleRetracted::class]);

    $editor->delete();

    Event::assertDispatchedTimes(RoleDeleted::class, 1);
    Event::assertNotDispatched(RoleRetracted::class);

    SoftDeletingRole::withTrashed()->whereKey($editor->getKey())->sole()->forceDelete();

    $deletions = Event::dispatched(RoleDeleted::class)->map(fn (array $arguments): RoleDeleted => $arguments[0])->values();

    expect($deletions)->toHaveCount(2)
        ->and($deletions[0]->heldGrants)->toBeEmpty()
        ->and($deletions[1]->heldGrants)->toHaveCount(1)
        ->and($deletions[1]->heldGrants[0]['permission']['name'])->toBe('publish')
        ->and(($this->retractions)()->sole()->authority->is($this->ana))->toBeTrue()
        ->and(Grant::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(AssignedRole::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('carries the deleted role in each retraction without the relations the caller loaded on it', function (): void {
    $this->warden->allow('editor')->to('publish');
    $this->warden->assign('editor')->to($this->ana);
    $editor = Role::query()->where('name', 'editor')->sole()->load('permissions');

    Event::fake([RoleRetracted::class]);

    $editor->delete();

    $retraction = ($this->retractions)()->sole();
    $restored = unserialize(serialize($retraction));

    expect($retraction->roles->sole()->getRelations())->toBeEmpty()
        ->and($retraction->assignments[0]->role->getRelations())->toBeEmpty()
        ->and($restored->assignments[0]->role->getRelations())->toBeEmpty()
        ->and($editor->relationLoaded('permissions'))->toBeTrue();
});

it('lets a cascaded retraction travel through a queue and come back', function (): void {
    $admin = User::query()->create(['name' => 'Admin']);
    $this->warden->assign('editor')->to($this->ana);
    $this->actingAs($admin);
    $captured = null;

    Event::listen(RoleRetracted::class, function (RoleRetracted $event) use (&$captured): void {
        $captured = $event;
    });

    Role::query()->where('name', 'editor')->sole()->delete();

    $copy = unserialize(serialize($captured));

    expect($copy)->toBeInstanceOf(RoleRetracted::class)
        ->and($copy->authority->is($this->ana))->toBeTrue()
        ->and($copy->actor?->is($admin))->toBeTrue()
        ->and($copy->roles->sole()->getAttribute('name'))->toBe('editor')
        ->and($copy->assignments[0]->role->getAttribute('name'))->toBe('editor');
});

it('lets a retraction listener already see the holder without the role', function (): void {
    config()->set('warden.cache.enabled', true);
    $this->warden->allow('editor')->to('publish');
    $this->warden->assign('editor')->to($this->ana);
    $seen = null;

    expect(Gate::forUser($this->ana)->allows('publish'))->toBeTrue();

    Event::listen(RoleRetracted::class, function (RoleRetracted $event) use (&$seen): void {
        $seen = Gate::forUser($event->authority)->allows('publish');
    });

    Role::query()->where('name', 'editor')->sole()->delete();

    expect($seen)->toBeFalse();
});

it('bumps each scope a deleted role reached once', function (): void {
    $luis = User::query()->create(['name' => 'Luis']);
    $this->warden->allow('editor')->to('publish');
    nestRole('auditor', 'editor');
    $this->warden->assign('editor')->to($this->ana);
    $editor = Role::query()->where('name', 'editor')->sole();
    $this->warden->tenant()->onceTo(7, fn () => $this->warden->assign($editor)->to($luis));
    Cache::store('array')->put('warden:v:a', 40, 60);
    Cache::store('array')->put('warden:v:g', 70, 60);

    $editor->delete();

    expect(Cache::store('array')->get('warden:v:a'))->toBe(42)
        ->and(Cache::store('array')->get('warden:v:g'))->toBe(71);
});
