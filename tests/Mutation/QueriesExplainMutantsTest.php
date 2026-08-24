<?php

declare(strict_types=1);

use ElPandaPe\Warden\Checks\Explain\AuthorizationExplanation;
use ElPandaPe\Warden\Checks\Explain\Cause;
use ElPandaPe\Warden\Checks\Verdict;
use ElPandaPe\Warden\Models\AssignedRole;
use ElPandaPe\Warden\Models\Grant;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\BoolCastAccount;
use ElPandaPe\Warden\Tests\Fixtures\BooleanCastAccount;
use ElPandaPe\Warden\Tests\Fixtures\StringKeyUser;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Support\Facades\Gate;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

beforeEach(function (): void {
    migrateWardenTables();

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
});

it('never lets a grant on another model type leak into the query', function (): void {
    Account::query()->create(['name' => 'One']);

    $this->warden->allow($this->user)->to('view', User::class);

    expect(Account::query()->whereCan($this->user, 'view')->count())->toBe(0);
});

it('compiles ownership grants for authorities with string keys', function (): void {
    $authority = StringKeyUser::query()->create(['name' => 'Stringy']);
    Account::query()->create(['name' => 'Mine', 'user_id' => (int) $authority->getKey()]);
    Account::query()->create(['name' => 'Other']);

    $this->warden->allow($authority)->toOwn(Account::class, 'edit');

    expect($authority->getKey())->toBeString()
        ->and(Account::query()->whereCan($authority, 'edit')->pluck('name')->all())->toBe(['Mine']);
});

it('compiles an unreadable authority column to an impossible condition, not to IS NULL', function (): void {
    Account::query()->create(['name' => 'NullOwner', 'user_id' => null]);

    $this->warden->allow($this->user)->to('view', Account::class)->whereColumn('user_id', 'missing_attr');

    expect(Account::query()->whereCan($this->user, 'view')->count())->toBe(0);
});

it('honors both bool and boolean cast spellings for boolean constraints', function (): void {
    Account::query()->create(['name' => 'Active', 'user_id' => 1]);

    $this->warden->allow($this->user)->to('view', BoolCastAccount::class)->where('user_id', true);
    $this->warden->allow($this->user)->to('view', BooleanCastAccount::class)->where('user_id', true);

    expect(BoolCastAccount::query()->whereCan($this->user, 'view')->count())->toBe(1)
        ->and(BooleanCastAccount::query()->whereCan($this->user, 'view')->count())->toBe(1);
});

it('renders via-role explanations without a role plainly', function (): void {
    $explanation = new AuthorizationExplanation(Verdict::granted(1), Cause::GrantedViaRole);

    expect((string) $explanation)->toBe('Granted by no permission.');
});

it('treats half-written role restrictions as restricted when explaining', function (): void {
    $this->warden->allow('editor')->to('publish');
    $this->warden->assign('editor')->to($this->user);
    $this->warden->allowEveryone()->to('publish');

    AssignedRole::query()->withoutGlobalScopes()->update(['restricted_to_type' => 'App\Models\Org']);

    $why = $this->warden->explain($this->user, 'publish');

    expect($why->cause)->toBe(Cause::GrantedToEveryone)
        ->and($why->role)->toBeNull();
});

it('collects every unrestricted role before blaming one', function (): void {
    $this->warden->allow('alpha')->to('unrelated');
    $this->warden->assign('alpha')->to($this->user);
    $this->warden->allow('bravo')->to('audit');
    $this->warden->assign('bravo')->to($this->user);

    $why = $this->warden->explain($this->user, 'audit');

    expect($why->cause)->toBe(Cause::GrantedViaRole)
        ->and($why->role?->getAttribute('name'))->toBe('bravo');
});

it('never blames a direct grant held by another authority', function (): void {
    $other = User::query()->create(['name' => 'Other']);
    $this->warden->allow($other)->to('audit');

    $this->warden->allow('admin')->to('audit');
    $this->warden->assign('admin')->to($this->user);

    $why = $this->warden->explain($this->user, 'audit');

    expect($why->cause)->toBe(Cause::GrantedViaRole)
        ->and($why->role?->getAttribute('name'))->toBe('admin');
});

it('stringifies keyless holders and keyless authorities identically', function (): void {
    $this->warden->allowEveryone()->to('haunt');

    $other = User::query()->create(['name' => 'Other']);
    $this->warden->allow($other)->to('haunt');

    // Degrade the direct grant's holder key to an empty string.
    Grant::query()->withoutGlobalScopes()->whereNotNull('entity_id')->update(['entity_id' => '']);

    // An unsaved authority has a null key, stringifying to that same empty string.
    $ghost = new User(['name' => 'Ghost']);

    expect($this->warden->explain($ghost, 'haunt')->cause)->toBe(Cause::GrantedDirectly);
})->skip(fn (): bool => Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'sqlite', 'Needs the UUID column variant; sqlite emulates it with loose typing');

it('normalizes non-list gate arguments before reading the entity', function (): void {
    $account = Account::query()->create(['name' => 'Acme']);

    $this->warden->allow($this->user)->to('edit', $account);

    expect(Gate::forUser($this->user)->allows('edit', [1 => $account]))->toBeTrue();
});
