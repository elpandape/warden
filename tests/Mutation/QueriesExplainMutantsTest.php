<?php

declare(strict_types=1);

use ElPandaPe\Warden\Checks\Explain\AuthorizationExplanation;
use ElPandaPe\Warden\Checks\Explain\Cause;
use ElPandaPe\Warden\Checks\Verdict;
use ElPandaPe\Warden\Models\AssignedRole;
use ElPandaPe\Warden\Models\Grant;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Support\Facades\Gate;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

// Fixtures for key and cast shapes the stock fixtures do not cover.
class MutationStringKeyUser extends User
{
    protected $table = 'users';

    protected $keyType = 'string';
}

class MutationBoolCastAccount extends Account
{
    protected $table = 'accounts';

    protected $casts = ['user_id' => 'bool'];
}

class MutationBooleanCastAccount extends Account
{
    protected $table = 'accounts';

    protected $casts = ['user_id' => 'boolean'];
}

beforeEach(function (): void {
    migrateWardenTables();

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
});

// Kills 82c40bb915eda359 (WhereCan::candidates entity_type filter removed).
it('never lets a grant on another model type leak into the query', function (): void {
    Account::query()->create(['name' => 'One']);

    $this->warden->allow($this->user)->to('view', User::class);

    // The grant targets User, not Account: no Account row may qualify.
    expect(Account::query()->whereCan($this->user, 'view')->count())->toBe(0);
});

// Kills c9bc299a02f8ab24 (WhereCan ownership guard negation removed).
it('compiles ownership grants for authorities with string keys', function (): void {
    $authority = MutationStringKeyUser::query()->create(['name' => 'Stringy']);
    Account::query()->create(['name' => 'Mine', 'user_id' => (int) $authority->getKey()]);
    Account::query()->create(['name' => 'Other']);

    $this->warden->allow($authority)->toOwn(Account::class, 'edit');

    expect($authority->getKey())->toBeString()
        ->and(Account::query()->whereCan($authority, 'edit')->pluck('name')->all())->toBe(['Mine']);
});

// Kills 3b1015476f0aeae3 (WhereCan impossible-condition early return removed).
it('keeps unreadable authority columns from matching null rows', function (): void {
    Account::query()->create(['name' => 'NullOwner', 'user_id' => null]);

    $this->warden->allow($this->user)->to('view', Account::class)->whereColumn('user_id', 'missing_attr');

    // An impossible condition, not "user_id IS NULL": nothing comes back.
    expect(Account::query()->whereCan($this->user, 'view')->count())->toBe(0);
});

// Kills cb9aab5a01b76beb and a384c1ebc3e80cc3 (hasCast list items removed).
it('honors both bool and boolean cast spellings for boolean constraints', function (): void {
    Account::query()->create(['name' => 'Active', 'user_id' => 1]);

    $this->warden->allow($this->user)->to('view', MutationBoolCastAccount::class)->where('user_id', true);
    $this->warden->allow($this->user)->to('view', MutationBooleanCastAccount::class)->where('user_id', true);

    // Either cast spelling lets the boolean constraint compile to SQL.
    expect(MutationBoolCastAccount::query()->whereCan($this->user, 'view')->count())->toBe(1)
        ->and(MutationBooleanCastAccount::query()->whereCan($this->user, 'view')->count())->toBe(1);
});

// Kills 954df55cd6512461 (AuthorizationExplanation $via fallback string).
it('renders via-role explanations without a role plainly', function (): void {
    $explanation = new AuthorizationExplanation(Verdict::granted(1), Cause::GrantedViaRole);

    expect((string) $explanation)->toBe('Granted by no permission.');
});

// Kills 9a02551325543e1f (Explainer unrestricted check && flipped to ||).
it('treats half-written role restrictions as restricted when explaining', function (): void {
    $this->warden->allow('editor')->to('publish');
    $this->warden->assign('editor')->to($this->user);
    $this->warden->allowEveryone()->to('publish');

    // A dangling restriction type with no id is not "unrestricted".
    AssignedRole::query()->withoutGlobalScopes()->update(['restricted_to_type' => 'App\Models\Org']);

    $why = $this->warden->explain($this->user, 'publish');

    expect($why->cause)->toBe(Cause::GrantedToEveryone)
        ->and($why->role)->toBeNull();
});

// Kills d2e4b9b87ef1bf5d (Explainer continue turned into break).
it('collects every unrestricted role before blaming one', function (): void {
    $this->warden->allow('alpha')->to('unrelated');
    $this->warden->assign('alpha')->to($this->user);
    $this->warden->allow('bravo')->to('audit');
    $this->warden->assign('bravo')->to($this->user);

    $why = $this->warden->explain($this->user, 'audit');

    expect($why->cause)->toBe(Cause::GrantedViaRole)
        ->and($why->role?->getAttribute('name'))->toBe('bravo');
});

// Kills 114e306850a97654 and 03a89e294112c51b (stringable collapses to '').
it('never blames a direct grant held by another authority', function (): void {
    $other = User::query()->create(['name' => 'Other']);
    $this->warden->allow($other)->to('audit');

    $this->warden->allow('admin')->to('audit');
    $this->warden->assign('admin')->to($this->user);

    $why = $this->warden->explain($this->user, 'audit');

    expect($why->cause)->toBe(Cause::GrantedViaRole)
        ->and($why->role?->getAttribute('name'))->toBe('admin');
});

// Kills 56b8d48774bcc64f (stringable null fallback string).
it('stringifies keyless holders and keyless authorities identically', function (): void {
    $this->warden->allowEveryone()->to('haunt');

    $other = User::query()->create(['name' => 'Other']);
    $this->warden->allow($other)->to('haunt');

    // Degrade the direct grant's holder key to an empty string.
    Grant::query()->withoutGlobalScopes()->whereNotNull('entity_id')->update(['entity_id' => '']);

    // An unsaved authority has a null key, which stringifies to the same
    // empty string: the direct grant is matched, and reported first.
    $ghost = new User(['name' => 'Ghost']);

    expect($this->warden->explain($ghost, 'haunt')->cause)->toBe(Cause::GrantedDirectly);
})->skip(fn (): bool => Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'sqlite', 'Needs the UUID column variant; sqlite emulates it with loose typing');

// Kills 835f5361496c46d3 (GateRegistrar array_values removed).
it('normalizes non-list gate arguments before reading the entity', function (): void {
    $account = Account::query()->create(['name' => 'Acme']);

    $this->warden->allow($this->user)->to('edit', $account);

    expect(Gate::forUser($this->user)->allows('edit', [1 => $account]))->toBeTrue();
});
