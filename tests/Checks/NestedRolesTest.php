<?php

declare(strict_types=1);

use ElPandaPe\Warden\Exceptions\ConfigurationException;
use ElPandaPe\Warden\Models\Relations\ReadOnlyBelongsToMany;
use ElPandaPe\Warden\Models\Role;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;
use function ElPandaPe\Warden\Tests\nestRole;

beforeEach(function (): void {
    migrateWardenTables();

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Ada']);
    $this->account = Account::query()->create(['name' => 'Acme']);
});

it('lends nothing through a nested role while the feature is off', function (): void {
    $this->warden->allow('auditor')->to('view', Account::class);
    nestRole('auditor', 'editor');
    $this->warden->assign('editor')->to($this->user);

    expect(Gate::forUser($this->user)->allows('view', $this->account))->toBeFalse();
});

it('lends the inner role grants once nesting is on', function (): void {
    config()->set('warden.roles.nested', true);

    $this->warden->allow('auditor')->to('view', Account::class);
    nestRole('auditor', 'editor');
    $this->warden->assign('editor')->to($this->user);

    expect(Gate::forUser($this->user)->allows('view', $this->account))->toBeTrue();
});

it('reaches deeper than one hop', function (): void {
    config()->set('warden.roles.nested', true);

    $this->warden->allow('auditor')->to('view', Account::class);
    nestRole('auditor', 'reviewer');
    nestRole('reviewer', 'editor');
    $this->warden->assign('editor')->to($this->user);

    expect(Gate::forUser($this->user)->allows('view', $this->account))->toBeTrue();
});

it('answers is() the way it answers can()', function (): void {
    config()->set('warden.roles.nested', true);

    nestRole('auditor', 'editor');
    $this->warden->assign('editor')->to($this->user);

    expect($this->warden->is($this->user)->an('auditor'))->toBeTrue();
});

it('answers whereIs() the way it answers is()', function (): void {
    config()->set('warden.roles.nested', true);

    nestRole('auditor', 'editor');
    $this->warden->assign('editor')->to($this->user);

    expect(User::query()->whereIs('auditor')->count())->toBe(1);
});

it('terminates on a cycle instead of recursing forever', function (): void {
    config()->set('warden.roles.nested', true);

    $this->warden->allow('auditor')->to('view', Account::class);
    nestRole('auditor', 'editor');
    nestRole('editor', 'auditor');
    $this->warden->assign('editor')->to($this->user);

    expect(Gate::forUser($this->user)->allows('view', $this->account))->toBeTrue();
});

it('keeps a query and a check agreeing over a nested role', function (): void {
    config()->set('warden.roles.nested', true);

    $this->warden->allow('auditor')->to('view', Account::class);
    nestRole('auditor', 'editor');
    $this->warden->assign('editor')->to($this->user);

    expect(Account::query()->whereCan($this->user, 'view')->count())->toBe(1)
        ->and($this->user)->toQueryExactlyWhatItCanCheck('view');
});

it('answers the same through the cached engine', function (): void {
    config()->set('warden.roles.nested', true);
    config()->set('warden.cache.enabled', true);
    $this->warden = app(Warden::class);

    $this->warden->allow('auditor')->to('view', Account::class);
    nestRole('auditor', 'editor');
    $this->warden->assign('editor')->to($this->user);

    expect(Gate::forUser($this->user)->allows('view', $this->account))->toBeTrue();
});

it('drops the nested reach the moment the flag goes off, without waiting for the cache', function (): void {
    config()->set('warden.roles.nested', true);
    config()->set('warden.cache.enabled', true);
    $this->warden = app(Warden::class);

    $this->warden->allow('auditor')->to('view', Account::class);
    nestRole('auditor', 'editor');
    $this->warden->assign('editor')->to($this->user);

    expect(Gate::forUser($this->user)->allows('view', $this->account))->toBeTrue();

    config()->set('warden.roles.nested', false);

    expect(Gate::forUser($this->user)->allows('view', $this->account))->toBeFalse();
});

it('exposes the nesting as a relation on the role itself', function (): void {
    nestRole('auditor', 'editor');

    $editor = Role::query()->where('name', 'editor')->sole();

    expect($editor->nestedRoles()->pluck('name')->all())->toBe(['auditor']);
});

it('walks a path whose links are both dated and still live', function (): void {
    config()->set('warden.roles.nested', true);
    config()->set('warden.cache.enabled', true);
    $this->warden = app(Warden::class);

    $this->warden->allow('auditor')->to('view', Account::class);
    $editor = Role::query()->firstOrCreate(['name' => 'editor']);
    $this->warden->assign('auditor')->until(Carbon::parse('2028-01-01 00:00:00'))->to($editor);
    $this->warden->assign('editor')->until(Carbon::parse('2027-01-01 00:00:00'))->to($this->user);

    Carbon::setTestNow(Carbon::parse('2026-07-01 00:00:00'));
    $this->warden->refresh();

    expect(Gate::forUser($this->user)->allows('view', $this->account))->toBeTrue();

    // Past the earlier of the two, the reach is gone even though the inner
    // edge runs another year.
    Carbon::setTestNow(Carbon::parse('2027-06-01 00:00:00'));
    $this->warden->refresh();

    expect(Gate::forUser($this->user)->allows('view', $this->account))->toBeFalse();

    Carbon::setTestNow();
});

it('answers whereIs() for a role nobody defined', function (): void {
    config()->set('warden.roles.nested', true);

    expect(User::query()->whereIs('ghost')->count())->toBe(0);
});

it('ends a dated grant borrowed through a dated assignment at the earlier one', function (): void {
    config()->set('warden.roles.nested', true);
    config()->set('warden.cache.enabled', true);
    $this->warden = app(Warden::class);

    $this->warden->allow('auditor')->until(Carbon::parse('2028-01-01 00:00:00'))->to('view', Account::class);
    $this->warden->assign('auditor')->until(Carbon::parse('2027-01-01 00:00:00'))->to($this->user);

    Carbon::setTestNow(Carbon::parse('2026-07-01 00:00:00'));
    $this->warden->refresh();

    expect(Gate::forUser($this->user)->allows('view', $this->account))->toBeTrue();

    // The assignment ends first, so the grant it lent ends with it.
    Carbon::setTestNow(Carbon::parse('2027-06-01 00:00:00'));
    $this->warden->refresh();

    expect(Gate::forUser($this->user)->allows('view', $this->account))->toBeFalse();

    Carbon::setTestNow();
});

it('answers isAll() the way it answers is()', function (): void {
    config()->set('warden.roles.nested', true);

    nestRole('auditor', 'editor');
    $this->warden->assign('editor')->to($this->user);

    expect($this->user->isAll('auditor', 'editor'))->toBeTrue()
        ->and($this->warden->is($this->user)->all('auditor'))->toBeTrue()
        ->and($this->user->isAll('auditor', 'ghost'))->toBeFalse();
});

it('answers isAll() through nesting even with the roles eager-loaded', function (): void {
    config()->set('warden.roles.nested', true);

    nestRole('auditor', 'editor');
    $this->warden->assign('editor')->to($this->user);
    $this->user->load('roles');

    expect($this->user->isAll('auditor'))->toBeTrue();
});

it('answers whereIsAll() the way it answers isAll()', function (): void {
    config()->set('warden.roles.nested', true);

    nestRole('auditor', 'editor');
    $this->warden->assign('editor')->to($this->user);
    $this->warden->assign('auditor')->to(User::query()->create(['name' => 'Grace']));

    expect(User::query()->whereIsAll('auditor', 'editor')->pluck('name')->all())->toBe(['Ada']);
});

it('answers whereIsNot() the way it answers is()', function (): void {
    config()->set('warden.roles.nested', true);

    nestRole('auditor', 'editor');
    $this->warden->assign('editor')->to($this->user);
    User::query()->create(['name' => 'Grace']);

    expect(User::query()->whereIsNot('auditor')->pluck('name')->all())->toBe(['Grace']);
});

it('answers isAll(), whereIsAll() and whereIsNot() for a role nobody defined', function (): void {
    config()->set('warden.roles.nested', true);

    $this->warden->assign('editor')->to($this->user);

    expect($this->user->isAll('ghost'))->toBeFalse()
        ->and(User::query()->whereIsAll('ghost')->count())->toBe(0)
        ->and(User::query()->whereIsNot('ghost')->count())->toBe(1);
});

it('reaches no nested role through an expired outer assignment in any scope', function (): void {
    config()->set('warden.roles.nested', true);

    nestRole('auditor', 'editor');
    $this->warden->assign('editor')->until(Carbon::parse('2027-01-01 00:00:00'))->to($this->user);

    Carbon::setTestNow(Carbon::parse('2027-06-01 00:00:00'));

    expect($this->user->isAll('auditor'))->toBeFalse()
        ->and(User::query()->whereIsAll('auditor')->count())->toBe(0)
        ->and(User::query()->whereIsNot('auditor')->count())->toBe(1);

    Carbon::setTestNow();
});

it('refuses every write through the nesting relation and writes nothing', function (string $writer, Closure $arguments): void {
    nestRole('auditor', 'editor');

    $editor = Role::query()->where('name', 'editor')->sole();
    $inner = Role::query()->where('name', 'auditor')->sole();
    $outsider = Role::query()->create(['name' => 'reviewer']);

    $roles = DB::table('roles')->orderBy('id')->get();
    $edges = DB::table('assigned_roles')->orderBy('id')->get();

    expect(fn (): mixed => $editor->nestedRoles()->{$writer}(...$arguments($inner, $outsider)))
        ->toThrow(ConfigurationException::class, 'nestedRoles()->'.$writer.'() is read-only: nest a role with Warden::assign($inner)->to($outer) and unnest it with Warden::retract($inner)->from($outer).')
        ->and(DB::table('roles')->orderBy('id')->get())->toEqual($roles)
        ->and(DB::table('assigned_roles')->orderBy('id')->get())->toEqual($edges);
})->with([
    ['attach', fn (Role $inner, Role $outsider): array => [$outsider]],
    ['attachOrFail', fn (Role $inner, Role $outsider): array => [$outsider]],
    ['detach', fn (Role $inner, Role $outsider): array => [$inner]],
    ['detachOrFail', fn (Role $inner, Role $outsider): array => [$inner]],
    ['sync', fn (Role $inner, Role $outsider): array => [[$outsider->getKey()]]],
    ['syncOrFail', fn (Role $inner, Role $outsider): array => [[$outsider->getKey()]]],
    ['syncWithoutDetaching', fn (Role $inner, Role $outsider): array => [[$outsider->getKey()]]],
    ['syncWithoutDetachingOrFail', fn (Role $inner, Role $outsider): array => [[$outsider->getKey()]]],
    ['syncWithPivotValues', fn (Role $inner, Role $outsider): array => [[$inner->getKey()], ['scope' => 5]]],
    ['syncWithPivotValuesOrFail', fn (Role $inner, Role $outsider): array => [[$inner->getKey()], ['scope' => 5]]],
    ['toggle', fn (Role $inner, Role $outsider): array => [[$inner->getKey()]]],
    ['toggleOrFail', fn (Role $inner, Role $outsider): array => [[$inner->getKey()]]],
    ['updateExistingPivot', fn (Role $inner, Role $outsider): array => [$inner->getKey(), ['scope' => 5]]],
    ['updateExistingPivotOrFail', fn (Role $inner, Role $outsider): array => [$inner->getKey(), ['scope' => 5]]],
    ['save', fn (Role $inner, Role $outsider): array => [new Role(['name' => 'publisher'])]],
    ['saveQuietly', fn (Role $inner, Role $outsider): array => [new Role(['name' => 'publisher'])]],
    ['saveMany', fn (Role $inner, Role $outsider): array => [[new Role(['name' => 'publisher'])]]],
    ['saveManyQuietly', fn (Role $inner, Role $outsider): array => [[new Role(['name' => 'publisher'])]]],
    ['create', fn (Role $inner, Role $outsider): array => [['name' => 'publisher']]],
    ['createMany', fn (Role $inner, Role $outsider): array => [[['name' => 'publisher']]]],
    ['firstOrCreate', fn (Role $inner, Role $outsider): array => [['name' => 'publisher']]],
    ['createOrFirst', fn (Role $inner, Role $outsider): array => [['name' => 'publisher']]],
    ['updateOrCreate', fn (Role $inner, Role $outsider): array => [['name' => 'auditor'], ['title' => 'Renamed']]],
]);

it('refuses every write through a loaded nesting pivot', function (string $writer, Closure $write): void {
    nestRole('auditor', 'editor');

    $editor = Role::query()->where('name', 'editor')->sole();
    $outsider = Role::query()->create(['name' => 'reviewer']);
    $pivot = $editor->nestedRoles->first()->pivot;

    $roles = DB::table('roles')->orderBy('id')->get();
    $edges = DB::table('assigned_roles')->orderBy('id')->get();

    expect(fn (): mixed => $write($pivot, $outsider))
        ->toThrow(ConfigurationException::class, $writer.'() on a nestedRoles() pivot is refused: nest a role with Warden::assign($inner)->to($outer) and unnest it with Warden::retract($inner)->from($outer).')
        ->and(DB::table('roles')->orderBy('id')->get())->toEqual($roles)
        ->and(DB::table('assigned_roles')->orderBy('id')->get())->toEqual($edges);
})->with([
    'pivot delete()' => ['delete', fn (Pivot $pivot, Role $outsider): mixed => $pivot->delete()],
    'pivot save()' => ['save', fn (Pivot $pivot, Role $outsider): mixed => $pivot->forceFill(['role_id' => $outsider->getKey()])->save()],
    'pivot update()' => ['save', fn (Pivot $pivot, Role $outsider): mixed => $pivot->update(['role_id' => $outsider->getKey()])],
    'pivot increment()' => ['increment', fn (Pivot $pivot, Role $outsider): mixed => $pivot->increment('role_id')],
    'pivot incrementEach()' => ['incrementEach', fn (Pivot $pivot, Role $outsider): mixed => $pivot->incrementEach(['role_id' => 1])],
    'pivot saveOrIgnore()' => ['saveOrIgnore', fn (Pivot $pivot, Role $outsider): mixed => $pivot->saveOrIgnore()],
]);

it('lets a role with loaded nested roles push when nothing changed', function (): void {
    nestRole('auditor', 'editor');

    $editor = Role::query()->where('name', 'editor')->sole();

    $roles = DB::table('roles')->orderBy('id')->get();
    $edges = DB::table('assigned_roles')->orderBy('id')->get();

    expect($editor->load('nestedRoles')->push())->toBeTrue()
        ->and(DB::table('roles')->orderBy('id')->get())->toEqual($roles)
        ->and(DB::table('assigned_roles')->orderBy('id')->get())->toEqual($edges);
});

it('reads the nesting through the read-only relation as before', function (): void {
    nestRole('auditor', 'editor');
    $this->warden->assign('reviewer')->to($this->user);

    $editor = Role::query()->where('name', 'editor')->sole();
    $inner = Role::query()->where('name', 'auditor')->sole();

    expect($editor->getKey())->toBe($this->user->getKey())
        ->and($editor->nestedRoles())->toBeInstanceOf(ReadOnlyBelongsToMany::class)
        ->and($editor->nestedRoles->pluck('name')->all())->toBe(['auditor'])
        ->and(Role::query()->with('nestedRoles')->whereKey($editor->getKey())->sole()->nestedRoles->pluck('name')->all())->toBe(['auditor'])
        ->and(Role::query()->whereHas('nestedRoles')->pluck('name')->all())->toBe(['editor'])
        ->and($editor->nestedRoles()->count())->toBe(1)
        ->and($editor->nestedRoles()->allRelatedIds()->all())->toEqual([$inner->getKey()]);
});

it('nests and unnests through assign() and retract() as before', function (): void {
    config()->set('warden.roles.nested', true);

    $editor = Role::query()->create(['name' => 'editor']);
    $this->warden->assign('editor')->to($this->user);
    $this->warden->assign('auditor')->to($editor);

    expect($editor->nestedRoles()->pluck('name')->all())->toBe(['auditor'])
        ->and($this->warden->is($this->user)->an('auditor'))->toBeTrue()
        ->and($this->warden->retract('auditor')->from($editor)->retractedCount())->toBe(1)
        ->and($editor->nestedRoles()->pluck('name')->all())->toBeEmpty()
        ->and($this->warden->is($this->user)->an('auditor'))->toBeFalse();
});
