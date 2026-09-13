<?php

declare(strict_types=1);

use ElPandaPe\Warden\Models\Role;
use ElPandaPe\Warden\Support\MorphHydrator;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\KeylessRole;
use ElPandaPe\Warden\Tests\Fixtures\StringKeyUser;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

beforeEach(function (): void {
    migrateWardenTables();
});

afterEach(function (): void {
    Relation::morphMap([], false);
    Relation::requireMorphMap(false);
});

it('keys a row by its type and id, whatever the id was stored as', function (): void {
    expect(MorphHydrator::key('warden.role', 7))->toBe("warden.role\x1f7")
        ->and(MorphHydrator::key('warden.role', '7'))->toBe(MorphHydrator::key('warden.role', 7));
});

it('reads every row of a type in one query', function (): void {
    $ana = User::query()->create(['name' => 'Ana']);
    $eva = User::query()->create(['name' => 'Eva']);
    $editor = Role::query()->create(['name' => 'editor']);

    DB::enableQueryLog();

    $found = MorphHydrator::many([
        [$ana->getMorphClass(), $ana->getKey()],
        [$eva->getMorphClass(), (string) $eva->getKey()],
        [$editor->getMorphClass(), $editor->getKey()],
        [$ana->getMorphClass(), $ana->getKey()],
    ]);

    expect(DB::getQueryLog())->toHaveCount(2)
        ->and($found)->toHaveCount(3)
        ->and($found[MorphHydrator::key($ana->getMorphClass(), $ana->getKey())]->is($ana))->toBeTrue()
        ->and($found[MorphHydrator::key($eva->getMorphClass(), $eva->getKey())]->is($eva))->toBeTrue()
        ->and($found[MorphHydrator::key($editor->getMorphClass(), $editor->getKey())]->is($editor))->toBeTrue();
});

it('reads a type in batches any engine can bind, and still warns once about a type no class maps', function (): void {
    Log::spy();
    $rows = array_map(fn (int $index): array => ['name' => "Holder {$index}"], range(1, 1001));

    foreach (array_chunk($rows, 500) as $batch) {
        DB::table('users')->insert($batch);
    }

    $morph = (new StringKeyUser)->getMorphClass();
    $holders = DB::table('users')->pluck('id')->map(fn (mixed $id): array => [$morph, (string) $id])->all();
    $unmapped = array_map(fn (int $id): array => ['nothing.maps.here', $id], range(1, 1001));

    DB::enableQueryLog();

    $found = MorphHydrator::many([...$holders, ...$unmapped]);

    expect(DB::getQueryLog())->toHaveCount(3)
        ->and($found)->toHaveCount(1001);
    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => $context['entity_type'] === 'nothing.maps.here' && count($context['ids']) === 1001,
    );
});

it('leaves out a row that is gone', function (): void {
    $ana = User::query()->create(['name' => 'Ana']);

    $found = MorphHydrator::many([
        [$ana->getMorphClass(), $ana->getKey()],
        [$ana->getMorphClass(), 999],
    ]);

    expect($found)->toHaveCount(1)
        ->toHaveKey(MorphHydrator::key($ana->getMorphClass(), $ana->getKey()));
});

it('leaves out a type no class maps, and warns once about it', function (): void {
    Log::spy();
    $ana = User::query()->create(['name' => 'Ana']);

    $found = MorphHydrator::many([
        ['nothing.maps.here', 1],
        ['nothing.maps.here', 2],
        [$ana->getMorphClass(), $ana->getKey()],
    ]);

    expect($found)->toHaveCount(1)
        ->toHaveKey(MorphHydrator::key($ana->getMorphClass(), $ana->getKey()));
    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => $message === 'Warden: no model class maps the morph type [nothing.maps.here], so its rows cannot be named.'
            && $context === ['entity_type' => 'nothing.maps.here', 'ids' => [1, 2]],
    );
});

it('reads past the global scopes that hide a row from the active tenant', function (): void {
    $tenancy = app(Warden::class)->tenant();
    $editor = $tenancy->onceTo(7, fn (): Role => Role::query()->create(['name' => 'editor']));
    $tenancy->to(8);

    expect(Role::query()->find($editor->getKey()))->toBeNull()
        ->and(MorphHydrator::many([[$editor->getMorphClass(), $editor->getKey()]]))
        ->toHaveKey(MorphHydrator::key($editor->getMorphClass(), $editor->getKey()));
});

it('resolves a type through the morph map', function (): void {
    Relation::enforceMorphMap(['account' => Account::class]);
    $acme = Account::query()->create(['name' => 'Acme']);

    $found = MorphHydrator::many([['account', $acme->getKey()]]);

    expect($found[MorphHydrator::key('account', $acme->getKey())]->is($acme))->toBeTrue();
});

it('leaves out a row whose key it cannot name', function (): void {
    $editor = Role::query()->create(['name' => 'editor']);

    expect(MorphHydrator::many([[KeylessRole::class, $editor->getKey()]]))->toBeEmpty();
});

it('stands in for a row that is gone with an unsaved instance that only knows its key', function (): void {
    $standIn = MorphHydrator::standIn((new Account)->getMorphClass(), 42);

    expect($standIn)->toBeInstanceOf(Account::class)
        ->and($standIn?->exists)->toBeFalse()
        ->and($standIn?->getKey())->toBe(42)
        ->and($standIn?->getAttributes())->toBe(['id' => 42]);
});

it('stands in through the morph map, keeping a string key', function (): void {
    Relation::morphMap(['stringy' => StringKeyUser::class]);

    $standIn = MorphHydrator::standIn('stringy', 'a1b2');

    expect($standIn)->toBeInstanceOf(StringKeyUser::class)
        ->and($standIn?->getKey())->toBe('a1b2');
});

it('has no stand-in for a type no class maps', function (): void {
    expect(MorphHydrator::standIn('nothing.maps.here', 42))->toBeNull();
});
