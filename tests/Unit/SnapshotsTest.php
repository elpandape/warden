<?php

declare(strict_types=1);

use ElPandaPe\Warden\Constraints\Builder;
use ElPandaPe\Warden\Constraints\ConstraintSerializer;
use ElPandaPe\Warden\Models\Permission;
use ElPandaPe\Warden\Models\Role;
use ElPandaPe\Warden\Support\PermissionIdentity;
use ElPandaPe\Warden\Support\Snapshots\PermissionSnapshot;
use ElPandaPe\Warden\Support\Snapshots\RoleSnapshot;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\CustomRole;
use ElPandaPe\Warden\Tests\Fixtures\KeylessPermission;
use ElPandaPe\Warden\Tests\Fixtures\KeylessRole;
use Illuminate\Database\Eloquent\MissingAttributeException;
use Illuminate\Database\Eloquent\Model;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

beforeEach(function (): void {
    migrateWardenTables();

    config()->set('warden.titles.autogenerate', false);
});

it('freezes the permission snapshot: keys, order and types', function (): void {
    $permission = Permission::query()->forceCreate([
        'id' => 41,
        'name' => 'edit',
        'title' => 'Edit live accounts',
        'entity_type' => Account::class,
        'entity_id' => null,
        'only_owned' => false,
        'options' => ConstraintSerializer::serialize(new Builder()->where('status', 'live')->group()),
        'scope' => 3,
    ])->refresh();

    expect(PermissionSnapshot::of($permission))->toBe([
        'v' => 1,
        'key' => 41,
        'name' => 'edit',
        'title' => 'Edit live accounts',
        'entity_type' => Account::class,
        'entity_id' => null,
        'only_owned' => false,
        'scope' => 3,
        'conditions' => ['g' => ['i' => [['and', ['c' => 'status', 'o' => '=', 't' => 'value', 'v' => 'live']]], 't' => 'group'], 'v' => 1],
    ]);
});

it('freezes the role snapshot: keys, order and types', function (): void {
    $role = Role::query()->forceCreate(['id' => 7, 'name' => 'editor', 'title' => 'Editor', 'scope' => 3])->refresh();

    expect(RoleSnapshot::of($role))->toBe(['v' => 1, 'key' => 7, 'name' => 'editor', 'title' => 'Editor', 'scope' => 3]);
});

it('pins every reach a permission snapshot can take', function (array $row, array $snapshot): void {
    $permission = Permission::query()->forceCreate($row)->refresh();

    expect(PermissionSnapshot::of($permission))->toBe($snapshot);
})->with([
    'plain' => [
        ['id' => 1, 'name' => 'ban-users'],
        ['v' => 1, 'key' => 1, 'name' => 'ban-users', 'title' => null, 'entity_type' => null, 'entity_id' => null, 'only_owned' => false, 'scope' => null, 'conditions' => null],
    ],
    'a class' => [
        ['id' => 2, 'name' => 'edit', 'entity_type' => Account::class],
        ['v' => 1, 'key' => 2, 'name' => 'edit', 'title' => null, 'entity_type' => Account::class, 'entity_id' => null, 'only_owned' => false, 'scope' => null, 'conditions' => null],
    ],
    'an instance' => [
        ['id' => 3, 'name' => 'view', 'entity_type' => Account::class, 'entity_id' => 7],
        ['v' => 1, 'key' => 3, 'name' => 'view', 'title' => null, 'entity_type' => Account::class, 'entity_id' => 7, 'only_owned' => false, 'scope' => null, 'conditions' => null],
    ],
    'everything' => [
        ['id' => 4, 'name' => '*', 'entity_type' => '*'],
        ['v' => 1, 'key' => 4, 'name' => '*', 'title' => null, 'entity_type' => '*', 'entity_id' => null, 'only_owned' => false, 'scope' => null, 'conditions' => null],
    ],
    'owned' => [
        ['id' => 5, 'name' => 'update', 'entity_type' => Account::class, 'only_owned' => true],
        ['v' => 1, 'key' => 5, 'name' => 'update', 'title' => null, 'entity_type' => Account::class, 'entity_id' => null, 'only_owned' => true, 'scope' => null, 'conditions' => null],
    ],
    'owned with conditions' => [
        ['id' => 6, 'name' => 'delete', 'entity_type' => Account::class, 'only_owned' => true, 'options' => ['v' => 1, 'g' => ['t' => 'group', 'i' => [['and', ['t' => 'value', 'c' => 'status', 'o' => '=', 'v' => 'live']]]]]],
        ['v' => 1, 'key' => 6, 'name' => 'delete', 'title' => null, 'entity_type' => Account::class, 'entity_id' => null, 'only_owned' => true, 'scope' => null, 'conditions' => ['g' => ['i' => [['and', ['c' => 'status', 'o' => '=', 't' => 'value', 'v' => 'live']]], 't' => 'group'], 'v' => 1]],
    ],
    'under a scope' => [
        ['id' => 7, 'name' => 'publish', 'entity_type' => Account::class, 'scope' => 3],
        ['v' => 1, 'key' => 7, 'name' => 'publish', 'title' => null, 'entity_type' => Account::class, 'entity_id' => null, 'only_owned' => false, 'scope' => 3, 'conditions' => null],
    ],
]);

it('reports a stored rule nobody can read as unreadable, never as no conditions', function (string $stored): void {
    $permission = new Permission()->setRawAttributes(['name' => 'edit', 'entity_type' => Account::class, 'options' => $stored]);

    expect(PermissionSnapshot::of($permission))->toBe([
        'v' => 1,
        'key' => null,
        'name' => 'edit',
        'title' => null,
        'entity_type' => Account::class,
        'entity_id' => null,
        'only_owned' => false,
        'scope' => null,
        'conditions' => ['unreadable' => $stored],
    ]);
})->with([
    'text that is not json' => '{not json',
    'an empty string' => '',
    'the json literal null' => 'null',
    'an unknown version' => '{"v":99,"g":null}',
    'an empty list' => '[]',
    'a json scalar, what the cast makes of an assigned string' => '"x"',
    'a rule the array cast encoded a second time' => json_encode(json_encode(['v' => 1, 'g' => ['t' => 'group', 'i' => []]])),
    'a group carrying the reserved not' => '{"v":1,"g":{"t":"group","i":[["not",{"t":"value","c":"status","o":"=","v":"live"}]]}}',
    'a malformed item' => '{"v":1,"g":{"t":"group","i":[["and"]]}}',
]);

it('keeps an empty group apart from no conditions', function (): void {
    $empty = new Permission(['name' => 'edit', 'entity_type' => Account::class, 'options' => ConstraintSerializer::serialize(new Builder()->group())]);
    $none = new Permission(['name' => 'edit', 'entity_type' => Account::class, 'options' => null]);

    expect(PermissionSnapshot::of($empty)['conditions'])->toBe(['g' => ['i' => [], 't' => 'group'], 'v' => 1])
        ->and(PermissionSnapshot::of($none)['conditions'])->toBeNull();
});

it('snapshots one rule the same way whatever key order or first connector was stored', function (): void {
    $conditions = fn (string $stored): ?array => PermissionSnapshot::of(
        new Permission()->setRawAttributes(['name' => 'edit', 'entity_type' => Account::class, 'options' => $stored]),
    )['conditions'];

    $canonical = ['g' => ['i' => [
        ['and', ['c' => 'status', 'o' => '=', 't' => 'value', 'v' => 'live']],
        ['or', ['i' => [
            ['and', ['c' => 'tier', 'o' => '>=', 't' => 'value', 'v' => 2]],
            ['and', ['a' => 'id', 'c' => 'owner_id', 'o' => '=', 't' => 'column']],
        ], 't' => 'group']],
    ], 't' => 'group'], 'v' => 1];

    expect($conditions('{"v":1,"g":{"t":"group","i":[["and",{"t":"value","c":"status","o":"=","v":"live"}],["or",{"t":"group","i":[["and",{"t":"value","c":"tier","o":">=","v":2}],["and",{"t":"column","c":"owner_id","o":"=","a":"id"}]]}]]}}'))->toBe($canonical)
        ->and($conditions('{"g":{"i":[["or",{"v":"live","o":"=","c":"status","t":"value"}],["or",{"i":[["or",{"v":2,"o":">=","c":"tier","t":"value"}],["and",{"a":"id","o":"=","c":"owner_id","t":"column"}]],"t":"group"}]],"t":"group"},"v":1}'))->toBe($canonical);
});

it('keeps value types load-bearing in conditions', function (): void {
    $conditions = fn (mixed $value): ?array => PermissionSnapshot::of(new Permission([
        'name' => 'edit',
        'entity_type' => Account::class,
        'options' => ConstraintSerializer::serialize(new Builder()->where('user_id', $value)->group()),
    ]))['conditions'];

    expect($conditions('1'))->toBe(['g' => ['i' => [['and', ['c' => 'user_id', 'o' => '=', 't' => 'value', 'v' => '1']]], 't' => 'group'], 'v' => 1])
        ->and($conditions(1))->toBe(['g' => ['i' => [['and', ['c' => 'user_id', 'o' => '=', 't' => 'value', 'v' => 1]]], 't' => 'group'], 'v' => 1]);
});

it('reads readable conditions back through the serializer and refuses the unreadable marker', function (): void {
    $group = new Builder()
        ->where('status', 'live')
        ->orWhere(fn (Builder $nested): Builder => $nested->where('tier', '>=', 2)->whereColumn('owner_id', 'id'))
        ->group();

    $readable = new Permission(['name' => 'edit', 'entity_type' => Account::class, 'options' => ConstraintSerializer::serialize($group)]);
    $unreadable = new Permission()->setRawAttributes(['name' => 'edit', 'entity_type' => Account::class, 'options' => '{not json']);

    expect(ConstraintSerializer::deserialize(PermissionSnapshot::of($readable)['conditions'])?->toArray())->toBe($group->toArray())
        ->and(ConstraintSerializer::deserialize(PermissionSnapshot::of($unreadable)['conditions']))->toBeNull();
});

it('survives a json round trip unchanged', function (array $snapshot): void {
    expect(json_decode(json_encode($snapshot, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR))->toBe($snapshot);
})->with([
    'a plain permission' => fn (): array => PermissionSnapshot::of(Permission::query()->forceCreate(['id' => 1, 'name' => 'ban-users'])->refresh()),
    'an owned instance rule with conditions under a scope' => fn (): array => PermissionSnapshot::of(Permission::query()->forceCreate([
        'id' => 2,
        'name' => 'edit',
        'title' => 'Edit account',
        'entity_type' => Account::class,
        'entity_id' => 7,
        'only_owned' => true,
        'scope' => 3,
        'options' => ['v' => 1, 'g' => ['t' => 'group', 'i' => [['or', ['t' => 'value', 'c' => 'tier', 'o' => '>=', 'v' => 2.5]], ['and', ['t' => 'group', 'i' => []]]]]],
    ])->refresh()),
    'an empty group' => fn (): array => PermissionSnapshot::of(new Permission(['name' => 'edit', 'entity_type' => Account::class, 'options' => ['v' => 1, 'g' => ['t' => 'group', 'i' => []]]])),
    'an unreadable rule' => fn (): array => PermissionSnapshot::of(new Permission()->setRawAttributes(['name' => 'edit', 'options' => '{not json'])),
    'a role under a scope' => fn (): array => RoleSnapshot::of(Role::query()->forceCreate(['id' => 3, 'name' => 'editor', 'title' => 'Editor', 'scope' => 3])->refresh()),
    'a keyless role' => fn (): array => RoleSnapshot::of(new KeylessRole(['name' => 'auditor'])),
]);

it('reads an integer key the same whether the driver or the caller typed it as a string', function (): void {
    $role = fn (mixed $key): Role => new Role()->setIncrementing(false)->setRawAttributes(['id' => $key, 'name' => 'editor', 'scope' => $key]);
    $permission = fn (mixed $id): Permission => new Permission()->setRawAttributes(['name' => 'view', 'entity_type' => Account::class, 'entity_id' => $id, 'scope' => $id]);

    expect(RoleSnapshot::of($role('7')))->toBe(RoleSnapshot::of($role(7)))
        ->and(RoleSnapshot::of($role('7')))->toBe(['v' => 1, 'key' => 7, 'name' => 'editor', 'title' => null, 'scope' => 7])
        ->and(RoleSnapshot::of($role('007')))->toBe(['v' => 1, 'key' => '007', 'name' => 'editor', 'title' => null, 'scope' => '007'])
        ->and(RoleSnapshot::of($role('9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d'))['key'])->toBe('9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d')
        ->and(PermissionSnapshot::of($permission('7')))->toBe(PermissionSnapshot::of($permission(7)))
        ->and(PermissionSnapshot::of($permission('7')))->toBe(['v' => 1, 'key' => null, 'name' => 'view', 'title' => null, 'entity_type' => Account::class, 'entity_id' => 7, 'only_owned' => false, 'scope' => 7, 'conditions' => null])
        ->and(PermissionSnapshot::of($permission('007')))->toBe(['v' => 1, 'key' => null, 'name' => 'view', 'title' => null, 'entity_type' => Account::class, 'entity_id' => '007', 'only_owned' => false, 'scope' => '007', 'conditions' => null]);
});

it('reads an identifier that is neither an integer nor a string as absent, as the identity print does', function (): void {
    $odd = new Permission()->setRawAttributes(['name' => 'view', 'entity_type' => Account::class, 'entity_id' => 7.5, 'scope' => true]);
    $absent = new Permission()->setRawAttributes(['name' => 'view', 'entity_type' => Account::class, 'entity_id' => null, 'scope' => null]);
    $oddRole = new Role()->setIncrementing(false)->setRawAttributes(['id' => 7.5, 'name' => 'editor', 'scope' => true]);

    expect(PermissionSnapshot::of($odd))->toBe(PermissionSnapshot::of($absent))
        ->and(PermissionIdentity::for($odd))->toBe(PermissionIdentity::for($absent))
        ->and(RoleSnapshot::of($oddRole))->toBe(['v' => 1, 'key' => null, 'name' => 'editor', 'title' => null, 'scope' => null]);
});

it('snapshots a row that has no name yet with an empty name', function (): void {
    expect(RoleSnapshot::of(new Role))->toBe(['v' => 1, 'key' => null, 'name' => '', 'title' => null, 'scope' => null])
        ->and(PermissionSnapshot::of(new Permission))->toBe(['v' => 1, 'key' => null, 'name' => '', 'title' => null, 'entity_type' => null, 'entity_id' => null, 'only_owned' => false, 'scope' => null, 'conditions' => null]);
});

it('reads options held raw as an array the way the engine reads them', function (): void {
    $conditions = fn (array $options): ?array => PermissionSnapshot::of(
        new Permission()->setRawAttributes(['name' => 'edit', 'entity_type' => Account::class, 'options' => $options]),
    )['conditions'];

    expect($conditions(['v' => 1, 'g' => ['t' => 'group', 'i' => []]]))->toBe(['g' => ['i' => [], 't' => 'group'], 'v' => 1])
        ->and($conditions(['v' => 99]))->toBe(['unreadable' => '{"v":99}']);
});

it('stamps the shape version', function (): void {
    expect(PermissionSnapshot::VERSION)->toBe(1)
        ->and(RoleSnapshot::VERSION)->toBe(1)
        ->and(PermissionSnapshot::of(new Permission)['v'])->toBe(PermissionSnapshot::VERSION)
        ->and(RoleSnapshot::of(new Role)['v'])->toBe(RoleSnapshot::VERSION);
});

it('answers the same through the model and the support class, custom models included', function (): void {
    $permission = Permission::query()->forceCreate(['id' => 5, 'name' => 'edit', 'title' => 'Edit accounts', 'entity_type' => Account::class]);
    $role = Role::query()->forceCreate(['id' => 6, 'name' => 'editor', 'title' => 'Editor']);
    $custom = CustomRole::query()->forceCreate(['id' => 9, 'name' => 'reviewer', 'title' => 'Reviewer']);
    $keylessRole = new KeylessRole(['name' => 'auditor', 'title' => 'Auditor']);
    $keylessPermission = new KeylessPermission(['name' => 'audit', 'title' => 'Audit']);

    expect($permission->snapshot())->toBe(PermissionSnapshot::of($permission))
        ->and($role->snapshot())->toBe(RoleSnapshot::of($role))
        ->and($custom->snapshot())->toBe(RoleSnapshot::of($custom))
        ->and($custom->snapshot())->toBe(['v' => 1, 'key' => 9, 'name' => 'reviewer', 'title' => 'Reviewer', 'scope' => null])
        ->and($keylessRole->snapshot())->toBe(RoleSnapshot::of($keylessRole))
        ->and($keylessRole->snapshot())->toBe(['v' => 1, 'key' => null, 'name' => 'auditor', 'title' => 'Auditor', 'scope' => null])
        ->and($keylessPermission->snapshot())->toBe(PermissionSnapshot::of($keylessPermission))
        ->and($keylessPermission->snapshot())->toBe(['v' => 1, 'key' => null, 'name' => 'audit', 'title' => 'Audit', 'entity_type' => null, 'entity_id' => null, 'only_owned' => false, 'scope' => null, 'conditions' => null]);
});

it('photographs a column a partial select left out as its default, and throws on it in strict mode', function (): void {
    Permission::query()->forceCreate([
        'id' => 41,
        'name' => 'edit',
        'title' => 'Edit live accounts',
        'entity_type' => Account::class,
        'entity_id' => 7,
        'only_owned' => true,
        'scope' => 3,
        'options' => ConstraintSerializer::serialize(new Builder()->where('status', 'live')->group()),
    ]);
    Role::query()->forceCreate(['id' => 7, 'name' => 'editor', 'title' => 'Editor', 'scope' => 3]);

    $permission = Permission::query()->select(['id', 'title'])->sole();
    $role = Role::query()->select(['id', 'title'])->sole();

    expect(PermissionSnapshot::of($permission))->toBe(['v' => 1, 'key' => 41, 'name' => '', 'title' => 'Edit live accounts', 'entity_type' => null, 'entity_id' => null, 'only_owned' => false, 'scope' => null, 'conditions' => null])
        ->and(RoleSnapshot::of($role))->toBe(['v' => 1, 'key' => 7, 'name' => '', 'title' => 'Editor', 'scope' => null]);

    Model::preventAccessingMissingAttributes();

    try {
        expect(fn (): array => PermissionSnapshot::of($permission))->toThrow(MissingAttributeException::class)
            ->and(fn (): array => RoleSnapshot::of($role))->toThrow(MissingAttributeException::class);
    } finally {
        Model::preventAccessingMissingAttributes(false);
    }
});
