<?php

declare(strict_types=1);

use ElPandaPe\Bouncer\Bouncer;
use ElPandaPe\Bouncer\Models\Grant;
use ElPandaPe\Bouncer\Models\Permission;
use ElPandaPe\Bouncer\Models\Role;
use ElPandaPe\Bouncer\Tests\Fixtures\Account;
use ElPandaPe\Bouncer\Tests\Fixtures\User;
use Illuminate\Support\Facades\Cache;

use function ElPandaPe\Bouncer\Tests\Database\migrateBouncerTables;

beforeEach(function (): void {
    migrateBouncerTables();

    $this->bouncer = app(Bouncer::class);
    $this->user = User::query()->create(['name' => 'Iris']);
});

// Kills 6a493b2df86ae8eb: a break after the permission model would drop every
// named permission that follows it in the same grant call.
it('grants named permissions that follow a permission model in one call', function (): void {
    $permission = Permission::query()->create(['name' => 'first']);

    $this->bouncer->allow($this->user)->to([$permission, 'second']);

    expect(Grant::query()->count())->toBe(2)
        ->and(Permission::query()->where('name', 'second')->exists())->toBeTrue();
});

// Kills 842235168c5b38b1: a break after the permission model would leave the
// names that follow it unrevoked.
it('revokes named permissions that follow a permission model in one call', function (): void {
    $permission = Permission::query()->create(['name' => 'first']);
    $this->bouncer->allow($this->user)->to([$permission, 'second']);

    $this->bouncer->disallow($this->user)->to([$permission, 'second']);

    expect(Grant::query()->count())->toBe(0);
});

// Kills d4d99f8ee832a4d5: without the early return for model-only lookups, the
// entity gets validated and an unsaved instance blows up the revoke.
it('revokes a permission model without touching the entity argument', function (): void {
    $permission = Permission::query()->create(['name' => 'archive']);
    $this->bouncer->allow($this->user)->to($permission);

    $this->bouncer->disallow($this->user)->to($permission, new Account);

    expect(Grant::query()->count())->toBe(0);
});

// Kills 4a18efe439735cbd: dropping entity_type from the null-entity attributes
// lets a plain grant absorb the class-wide permission of the same name.
it('keeps a plain grant apart from a class-wide permission of the same name', function (): void {
    $this->bouncer->allow($this->user)->to('view', Account::class);

    $this->bouncer->allow($this->user)->to('view');

    expect(Permission::query()->where('name', 'view')->count())->toBe(2)
        ->and(Permission::query()->where('name', 'view')->whereNull('entity_type')->exists())->toBeTrue();
});

// Kills 8e368976d87322a9: dropping entity_id from the null-entity attributes
// lets a plain grant reuse a stray row that carries an entity id.
it('keeps a plain grant apart from a stray row carrying an entity id', function (): void {
    Permission::query()->create(['name' => 'stray', 'entity_id' => 7]);

    $this->bouncer->allow($this->user)->to('stray');

    expect(Permission::query()->where('name', 'stray')->count())->toBe(2)
        ->and(Permission::query()->where('name', 'stray')->whereNull('entity_id')->exists())->toBeTrue();
});

// Kills e09392e174e052fc: dropping entity_id from the blanket-entity attributes
// lets a blanket grant reuse a stray blanket row that carries an entity id.
it('keeps a blanket grant apart from a stray blanket row carrying an entity id', function (): void {
    Permission::query()->create(['name' => 'publish', 'entity_type' => '*', 'entity_id' => 3]);

    $this->bouncer->allow($this->user)->to('publish', '*');

    expect(Permission::query()->where('name', 'publish')->count())->toBe(2)
        ->and(
            Permission::query()->where('name', 'publish')->where('entity_type', '*')->whereNull('entity_id')->exists(),
        )->toBeTrue();
});

// Kills 2b063baf9c075fa6: removing the left side of the coalesce makes every
// revoke by role name throw RoleDoesNotExist even when the role exists.
it('revokes from an existing role resolved by name', function (): void {
    $this->bouncer->allow('editor')->to('edit-posts');

    $this->bouncer->disallow('editor')->to('edit-posts');

    expect(Grant::query()->count())->toBe(0)
        ->and(Role::query()->where('name', 'editor')->exists())->toBeTrue();
});

// Kills c08722b1e67668e6 and f9a4e55737e40750: at transaction level zero both
// mutants take the after-commit branch, whose callback runs immediately with
// no open transaction, so the counters would advance twice per write.
it('bumps the cache version exactly once for a write outside a transaction', function (): void {
    config()->set('bouncer.cache.enabled', true);
    Cache::store('array')->put('bouncer:v:a', 40, 60);
    Cache::store('array')->put('bouncer:v:g', 70, 60);

    $this->bouncer->allow($this->user)->to('edit-site');

    expect(Cache::store('array')->get('bouncer:v:a'))->toBe(41)
        ->and(Cache::store('array')->get('bouncer:v:g'))->toBe(71);
});
