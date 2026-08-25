<?php

declare(strict_types=1);

use ElPandaPe\Warden\Models\Permission;
use ElPandaPe\Warden\Support\PermissionIdentity;
use ElPandaPe\Warden\Testing\Schema as WardenSchema;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use function ElPandaPe\Warden\Tests\Database\migrateLegacyCatalog;

beforeEach(function (): void {
    migrateLegacyCatalog();

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
});

it('leaves a pre-2.0 catalog unable to write until it is upgraded', function (): void {
    expect(fn () => Permission::query()->create(['name' => 'publish']))
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('gives every existing row the identity the resolver computes', function (): void {
    DB::table('permissions')->insert([
        ['name' => 'publish', 'entity_type' => null, 'entity_id' => null, 'only_owned' => false, 'scope' => null],
        ['name' => 'edit', 'entity_type' => Account::class, 'entity_id' => null, 'only_owned' => true, 'scope' => 5],
    ]);

    WardenSchema::upgradeToV2();

    $rows = Permission::query()->withoutGlobalScopes()->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->every(fn (Permission $row): bool => $row->getAttribute('identity_key') === PermissionIdentity::for($row)))
        ->toBeTrue();
});

it('writes and checks normally once upgraded', function (): void {
    WardenSchema::upgradeToV2();

    $this->warden->allow($this->user)->to('publish');

    expect(Illuminate\Support\Facades\Gate::forUser($this->user)->allows('publish'))->toBeTrue();
});

it('refuses the unique index while rows still identify the same permission', function (): void {
    DB::table('permissions')->insert([
        ['name' => 'publish', 'entity_type' => null, 'entity_id' => null, 'only_owned' => false, 'scope' => null],
        ['name' => 'publish', 'entity_type' => null, 'entity_id' => null, 'only_owned' => false, 'scope' => null],
    ]);

    expect(fn () => WardenSchema::upgradeToV2())
        ->toThrow(RuntimeException::class, 'warden:clean --duplicates')
        ->and(Schema::hasColumn('permissions', 'identity_key'))->toBeTrue()
        ->and(Schema::hasIndex('permissions', 'permissions_identity_unique'))->toBeFalse();
});

it('finishes on the run that follows the cleanup, keeping the grants', function (): void {
    $ada = User::query()->create(['name' => 'Ada']);

    DB::table('permissions')->insert([
        ['name' => 'publish', 'entity_type' => null, 'entity_id' => null, 'only_owned' => false, 'scope' => null],
        ['name' => 'publish', 'entity_type' => null, 'entity_id' => null, 'only_owned' => false, 'scope' => null],
    ]);

    foreach ([[1, $this->user], [2, $ada]] as [$permissionId, $holder]) {
        DB::table('grants')->insert([
            'permission_id' => $permissionId,
            'entity_type' => $holder->getMorphClass(),
            'entity_id' => $holder->getKey(),
            'forbidden' => false,
            'scope' => null,
        ]);
    }

    rescue(fn () => WardenSchema::upgradeToV2());

    $this->artisan('warden:clean', ['--duplicates' => true])->assertSuccessful();

    WardenSchema::upgradeToV2();

    expect(Schema::hasIndex('permissions', 'permissions_identity_unique'))->toBeTrue()
        ->and(Permission::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(Illuminate\Support\Facades\Gate::forUser($this->user)->allows('publish'))->toBeTrue()
        ->and(Illuminate\Support\Facades\Gate::forUser($ada)->allows('publish'))->toBeTrue();
});
