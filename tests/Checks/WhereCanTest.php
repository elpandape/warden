<?php

declare(strict_types=1);

use ElPandaPe\Warden\Models\Permission;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\BoolCastAccount;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

beforeEach(function (): void {
    migrateWardenTables();

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
});

it('returns the rows a class-wide grant covers, minus forbids', function (): void {
    $one = Account::query()->create(['name' => 'One'])->refresh();
    Account::query()->create(['name' => 'Two'])->refresh();

    $this->warden->allow($this->user)->to('view', Account::class);
    $this->warden->forbid($this->user)->to('view', $one);

    expect(Account::query()->whereCan($this->user, 'view')->pluck('name')->all())->toBe(['Two'])
        ->and($this->user)->toQueryExactlyWhatItCanCheck('view');
});

it('returns instance grants, wildcard grants and everyone-grants', function (): void {
    $mine = Account::query()->create(['name' => 'Mine'])->refresh();
    Account::query()->create(['name' => 'Other'])->refresh();

    $this->warden->allow($this->user)->to('edit', $mine);
    $this->warden->allowEveryone()->to('browse', Account::class);

    expect(Account::query()->whereCan($this->user, 'edit')->pluck('name')->all())->toBe(['Mine'])
        ->and(Account::query()->whereCan($this->user, 'browse')->count())->toBe(2)
        ->and($this->user)->toQueryExactlyWhatItCanCheck('edit');

    // The full wildcard widens every check, and the query follows.
    $this->warden->allow($this->user)->everything();

    expect(Account::query()->whereCan($this->user, 'edit')->count())->toBe(2)
        ->and(Account::query()->whereCan($this->user, 'anything')->count())->toBe(2)
        ->and($this->user)->toQueryExactlyWhatItCanCheck('edit');
});

it('returns nothing without a matching grant', function (): void {
    Account::query()->create(['name' => 'One']);

    expect(Account::query()->whereCan($this->user, 'view')->count())->toBe(0)
        ->and($this->user)->toQueryExactlyWhatItCanCheck('view');
});

it('compiles ownership grants to the owner attribute', function (): void {
    $mine = Account::query()->create(['name' => 'Mine', 'user_id' => $this->user->getKey()])->refresh();
    Account::query()->create(['name' => 'Other'])->refresh();

    $this->warden->allow($this->user)->toOwn(Account::class, 'edit');

    expect(Account::query()->whereCan($this->user, 'edit')->pluck('name')->all())->toBe(['Mine'])
        ->and($this->user)->toQueryExactlyWhatItCanCheck('edit');
});

it('excludes ownership grants resolved by closures, fail-closed', function (): void {
    Account::query()->create(['name' => 'Mine', 'user_id' => $this->user->getKey()])->refresh();

    $this->warden->ownedVia(fn (Model $entity, Model $authority): bool => true);
    $this->warden->allow($this->user)->toOwn(Account::class, 'edit');

    // The closure cannot become SQL: the query stays empty even though can() passes.
    expect(Account::query()->whereCan($this->user, 'edit')->count())->toBe(0);
});

it('compiles constraints with sql precedence', function (): void {
    $published = Account::query()->create(['name' => 'Post', 'user_id' => 7])->refresh();
    Account::query()->create(['name' => 'Draft', 'user_id' => 8])->refresh();
    $ownDraft = Account::query()->create(['name' => 'Draft', 'user_id' => $this->user->getKey()])->refresh();

    $this->warden->allow($this->user)->to('view', Account::class)
        ->where('name', 'Post')
        ->orWhere('name', 'Draft')->whereColumn('user_id', 'id');

    expect(Account::query()->whereCan($this->user, 'view')->pluck('id')->sort()->values()->all())
        ->toBe([$published->getKey(), $ownDraft->getKey()])
        ->and($this->user)->toQueryExactlyWhatItCanCheck('view');
});

it('blocks shape rows entirely when a forbid is inexpressible', function (): void {
    Account::query()->create(['name' => 'One', 'user_id' => $this->user->getKey()])->refresh();

    $this->warden->allow($this->user)->to('view', Account::class);
    $this->warden->ownedVia(fn (): bool => false);
    $this->warden->forbid($this->user)->toOwn(Account::class, 'view');

    // The owned forbid cannot compile: fail closed, nothing comes back.
    expect(Account::query()->whereCan($this->user, 'view')->count())->toBe(0);
});

it('honors role grants and tenancy', function (): void {
    $this->warden->tenant()->to(1);
    $inTenant = Account::query()->create(['name' => 'T1'])->refresh();

    $this->warden->allow('editor')->to('edit', Account::class);
    $this->warden->assign('editor')->to($this->user);

    expect(Account::query()->whereCan($this->user, 'edit')->count())->toBe(1);

    $this->warden->tenant()->to(2);

    expect(Account::query()->whereCan($this->user, 'edit')->count())->toBe(0)
        ->and($this->user)->toQueryExactlyWhatItCanCheck('edit');
});

it('excludes restricted role assignments, fail-closed', function (): void {
    $org = Account::query()->create(['name' => 'Org'])->refresh();

    $this->warden->allow('editor')->to('edit', Account::class);
    $this->warden->assign('editor')->on($org)->to($this->user);

    // A restricted editor is not a queryable global editor.
    expect(Account::query()->whereCan($this->user, 'edit')->count())->toBe(0);
});

it('works through the global macro for models without the trait', function (): void {
    $this->warden->allow($this->user)->to('probe', User::class);

    /** @phpstan-ignore method.notFound */
    expect(User::query()->whereCan($this->user, 'probe')->count())->toBe(1);
});

it('paginates like any other scope', function (): void {
    foreach (range(1, 5) as $i) {
        Account::query()->create(['name' => "A{$i}"]);
    }

    $this->warden->allow($this->user)->to('view', Account::class);

    expect(Account::query()->whereCan($this->user, 'view')->paginate(2)->total())->toBe(5);
});

it('skips corrupt constraint candidates and blocks corrupt forbids', function (): void {
    Account::query()->create(['name' => 'One'])->refresh();

    $this->warden->allow($this->user)->to('view', Account::class)->where('name', 'One');
    Permission::query()->withoutGlobalScopes()
        ->whereNotNull('options')->update(['options' => ['v' => 99, 'g' => 'junk']]);

    // Granted side: the undecidable candidate is skipped, nothing comes back.
    expect(Account::query()->whereCan($this->user, 'view')->count())->toBe(0);

    $this->warden->allow($this->user)->to('view', Account::class);
    $this->warden->forbid($this->user)->to('view', Account::class)->where('name', 'Two');
    Permission::query()->withoutGlobalScopes()
        ->whereNotNull('options')->update(['options' => ['v' => 99, 'g' => 'junk']]);

    // Forbidden side: the undecidable forbid blocks every shape row.
    expect(Account::query()->whereCan($this->user, 'view')->count())->toBe(0);
});

it('compiles nested groups', function (): void {
    $match = Account::query()->create(['name' => 'X', 'user_id' => 5])->refresh();
    Account::query()->create(['name' => 'Y', 'user_id' => 5])->refresh();

    $this->warden->allow($this->user)->to('view', Account::class)
        ->where(function (ElPandaPe\Warden\Constraints\Builder $group): void {
            $group->where('name', 'X')->orWhere('name', 'Z');
        })
        ->where('user_id', 5);

    expect(Account::query()->whereCan($this->user, 'view')->pluck('id')->all())->toBe([$match->getKey()])
        ->and($this->user)->toQueryExactlyWhatItCanCheck('view');
});

it('compiles an unreadable authority column to an impossible condition', function (): void {
    Account::query()->create(['name' => 'X', 'user_id' => 5])->refresh();

    $this->warden->allow($this->user)->to('view', Account::class)->whereColumn('user_id', 'missing_attr');

    expect(Account::query()->whereCan($this->user, 'view')->count())->toBe(0);
});

it('blocks forbids carried by restricted roles instead of dropping them', function (): void {
    $org = Account::query()->create(['name' => 'Org'])->refresh();
    Account::query()->create(['name' => 'Elsewhere'])->refresh();

    $this->warden->allow($this->user)->to('view', Account::class);
    $this->warden->forbid('auditor')->to('view', Account::class);
    $this->warden->assign('auditor')->on($org)->to($this->user);

    // can() denies inside the context; the query must never return $org.
    expect(Gate::forUser($this->user)->allows('view', $org))->toBeFalse()
        ->and(Account::query()->whereCan($this->user, 'view')->pluck('id')->all())
        ->not->toContain($org->getKey());
});

it('keeps empty constraint groups from erasing forbid branches', function (): void {
    Account::query()->create(['name' => 'One'])->refresh();

    $this->warden->allow($this->user)->to('view', Account::class);
    $this->warden->forbid($this->user)->to('view', Account::class)->where(function (ElPandaPe\Warden\Constraints\Builder $group): void {
        // Intentionally empty: passes trivially, must still block in SQL.
    });

    expect(Account::query()->whereCan($this->user, 'view')->count())->toBe(0)
        ->and($this->user)->toQueryExactlyWhatItCanCheck('view');
});

it('treats boolean constraints without a cast as impossible, like can()', function (): void {
    Account::query()->create(['name' => 'One', 'user_id' => 1])->refresh();

    $this->warden->allow($this->user)->to('view', Account::class)->where('user_id', true);

    // The strict comparator can never match int 1 to bool true: parity is empty.
    expect(Account::query()->whereCan($this->user, 'view')->count())->toBe(0);
    expect($this->user)->toQueryExactlyWhatItCanCheck('view');
});

it('blocks only the pinned row when a forbid cannot be expressed in sql', function (): void {
    $one = Account::query()->create(['name' => 'One'])->refresh();
    Account::query()->create(['name' => 'Two'])->refresh();

    $this->warden->allow($this->user)->to('view', Account::class);
    $this->warden->forbid($this->user)->to('view', $one)->where('name', '=', 'One');

    DB::table('permissions')->whereNotNull('options')->update(['options' => '{not json']);
    $this->warden->refresh();

    expect(Account::query()->whereCan($this->user, 'view')->pluck('name')->all())->toBe(['Two']);
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'sqlite',
    'Engines with a real json type reject the blob on write, so the row cannot exist there',
);

it('compiles an impossible predicate for a non-boolean value on a bool-cast column', function (): void {
    $this->warden->allow($this->user)->to('view', BoolCastAccount::class)->where('user_id', '=', 'true');

    expect(BoolCastAccount::query()->whereCan($this->user, 'view')->toSql())->toContain('0 = 1');
});

it('fails closed instead of emitting sql for an ownership column the table lacks', function (): void {
    $this->warden->allow($this->user)->toOwn(User::class, 'edit');

    expect(User::query()->whereCan($this->user, 'edit')->count())->toBe(0)
        ->and(Gate::forUser($this->user)->allows('edit', $this->user))->toBeFalse();
});

it('blocks every row when an inexpressible ownership forbid is in force', function (): void {
    Account::query()->create(['name' => 'One'])->refresh();

    $this->warden->allow($this->user)->to('view', User::class);
    $this->warden->forbid($this->user)->toOwn(User::class, 'view');

    expect(User::query()->whereCan($this->user, 'view')->count())->toBe(0);
});

it('hydrates only the catalog rows the authority could hold', function (): void {
    $mine = Account::query()->create(['name' => 'Mine'])->refresh();

    $this->warden->allow($this->user)->to('view', Account::class);

    foreach (range(1, 20) as $id) {
        Permission::query()->create(['name' => 'view', 'entity_type' => Account::class, 'entity_id' => $id]);
    }

    DB::enableQueryLog();
    Account::query()->whereCan($this->user, 'view')->count();

    $catalog = collect(DB::getQueryLog())
        ->first(fn (array $entry): bool => str_contains($entry['query'], 'permissions')
            && str_contains($entry['query'], 'select'));

    expect($catalog['query'] ?? '')->toContain('exists')
        ->and(Account::query()->whereCan($this->user, 'view')->pluck('name')->all())->toBe([$mine->name]);
});

it('emits a real tautology for an empty group already stored', function (): void {
    $one = Account::query()->create(['name' => 'One'])->refresh();

    $this->warden->allow($this->user)->to('view', Account::class);

    // The API refuses to write this now; a row from before it did still has to
    // compile, and to compile to something the builder cannot drop.
    Permission::query()->where('name', 'view')->update([
        'options' => json_encode(['v' => 1, 'g' => ['t' => 'group', 'i' => []]]),
    ]);
    $this->warden->refresh();

    expect(Account::query()->whereCan($this->user, 'view')->pluck('id')->all())->toBe([$one->getKey()]);
});

it('reads assigned_roles once per call, not once per pass', function (): void {
    Account::query()->create(['name' => 'Acme']);
    $this->warden->allow('editor')->to('view', Account::class);
    $this->warden->assign('editor')->to($this->user);

    DB::flushQueryLog();
    DB::enableQueryLog();

    Account::whereCan($this->user, 'view')->get();

    $log = DB::getQueryLog();
    $assignments = array_filter(
        $log,
        fn (array $entry): bool => str_contains((string) $entry['query'], 'assigned_roles'),
    );

    expect($assignments)->toHaveCount(1)
        ->and($log)->toHaveCount(5);
});
