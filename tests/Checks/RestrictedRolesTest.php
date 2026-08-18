<?php

declare(strict_types=1);

use ElPandaPe\Warden\Events\RoleAssigned;
use ElPandaPe\Warden\Events\RoleRetracted;
use ElPandaPe\Warden\Exceptions\ConfigurationException;
use ElPandaPe\Warden\Models\AssignedRole;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

beforeEach(function (): void {
    migrateWardenTables();

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
    $this->orgOne = Account::query()->create(['name' => 'Org One'])->refresh();
    $this->orgTwo = Account::query()->create(['name' => 'Org Two'])->refresh();

    $this->warden->allow('editor')->to('edit', Account::class);
});

function projectIn(Account $org): Account
{
    return Account::query()->create(['name' => 'Project', 'account_id' => $org->getKey()])->refresh();
}

it('holds the same role in several contexts at once', function (): void {
    $this->warden->assign('editor')->on($this->orgOne)->to($this->user);
    $this->warden->assign('editor')->on($this->orgTwo)->to($this->user);

    $inOne = projectIn($this->orgOne);
    $orphan = Account::query()->create(['name' => 'Loose'])->refresh();

    expect(AssignedRole::query()->count())->toBe(2)
        ->and(Gate::forUser($this->user)->allows('edit', $inOne))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('edit', projectIn($this->orgTwo)))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('edit', $orphan))->toBeFalse();
});

it('authorizes the context model itself', function (): void {
    $this->warden->assign('editor')->on($this->orgOne)->to($this->user);

    expect(Gate::forUser($this->user)->allows('edit', $this->orgOne))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('edit', $this->orgTwo))->toBeFalse();
});

it('contributes nothing to instance-less checks', function (): void {
    $this->warden->allow('editor')->to('publish');
    $this->warden->assign('editor')->on($this->orgOne)->to($this->user);

    expect(Gate::forUser($this->user)->allows('publish'))->toBeFalse()
        ->and(Gate::forUser($this->user)->allows('edit', Account::class))->toBeFalse();

    // An unrestricted assignment of the same role restores the simple check.
    $this->warden->assign('editor')->to($this->user);

    expect(Gate::forUser($this->user)->allows('publish'))->toBeTrue();
});

it('retracts one context or every assignment', function (): void {
    $this->warden->assign('editor')->on($this->orgOne)->to($this->user);
    $this->warden->assign('editor')->on($this->orgTwo)->to($this->user);

    $this->warden->retract('editor')->on($this->orgOne)->from($this->user);

    expect(AssignedRole::query()->count())->toBe(1)
        ->and(Gate::forUser($this->user)->allows('edit', projectIn($this->orgTwo)))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('edit', projectIn($this->orgOne)))->toBeFalse();

    $this->warden->retract('editor')->from($this->user);

    expect(AssignedRole::query()->count())->toBe(0);
});

it('resolves membership through configuration', function (): void {
    $this->warden->restrictedVia(Account::class, 'owner_id');
    $owned = Account::query()->create(['name' => 'Held', 'owner_id' => $this->orgOne->getKey()])->refresh();

    $this->warden->assign('editor')->on($this->orgOne)->to($this->user);

    expect(Gate::forUser($this->user)->allows('edit', $owned))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('edit', projectIn($this->orgOne)))->toBeFalse();
});

it('resolves membership through a global closure', function (): void {
    $this->warden->restrictedVia(
        fn (Model $entity, Model $context): bool => $entity->getAttribute('name') === 'Chosen',
    );

    $chosen = Account::query()->create(['name' => 'Chosen'])->refresh();
    $other = Account::query()->create(['name' => 'Other'])->refresh();

    $this->warden->assign('editor')->on($this->orgOne)->to($this->user);

    expect(Gate::forUser($this->user)->allows('edit', $chosen))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('edit', $other))->toBeFalse();
});

it('rejects late or unsaved restriction contexts', function (): void {
    expect(fn () => $this->warden->assign('editor')->to($this->user)->on($this->orgOne))
        ->toThrow(ConfigurationException::class, 'before to()')
        ->and(fn () => $this->warden->assign('editor')->on(new Account(['name' => 'Ghost'])))
        ->toThrow(ConfigurationException::class, 'saved model')
        ->and(fn () => $this->warden->retract('editor')->from($this->user)->on($this->orgOne))
        ->toThrow(ConfigurationException::class, 'before from()');
});

it('composes with tenancy', function (): void {
    $this->warden->tenant()->to(1);
    $this->warden->allow('editor')->to('edit', Account::class);
    $this->warden->assign('editor')->on($this->orgOne)->to($this->user);

    $project = projectIn($this->orgOne);

    expect(Gate::forUser($this->user)->allows('edit', $project))->toBeTrue();

    $this->warden->tenant()->to(2);

    expect(Gate::forUser($this->user)->allows('edit', $project))->toBeFalse();
});

it('announces the restriction context in role events', function (): void {
    Event::fake([RoleAssigned::class, RoleRetracted::class]);

    $this->warden->assign('editor')->on($this->orgOne)->to($this->user);
    $this->warden->retract('editor')->on($this->orgOne)->from($this->user);

    Event::assertDispatched(RoleAssigned::class, fn (RoleAssigned $event): bool => $event->restrictedTo?->is($this->orgOne) === true);
    Event::assertDispatched(RoleRetracted::class, fn (RoleRetracted $event): bool => $event->restrictedTo?->is($this->orgOne) === true);
});

it('keeps role membership checks unaware of restrictions', function (): void {
    $this->warden->assign('editor')->on($this->orgOne)->to($this->user);

    // isA answers "holds the role", context or not — documented semantics.
    expect($this->user->isAn('editor'))->toBeTrue();
});

it('serves restricted roles and constraints from the cache engine', function (): void {
    config()->set('warden.cache.enabled', true);

    $this->warden->assign('editor')->on($this->orgOne)->to($this->user);
    $this->warden->allow($this->user)->to('view', Account::class)->where('name', 'Project');
    $this->warden->allow($this->user)->to('publish')->where('name', 'X');

    $inOne = projectIn($this->orgOne);
    $loose = Account::query()->create(['name' => 'Loose'])->refresh();

    expect(Gate::forUser($this->user)->allows('edit', $inOne))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('edit', $loose))->toBeFalse()
        ->and(Gate::forUser($this->user)->allows('edit', Account::class))->toBeFalse()
        ->and(Gate::forUser($this->user)->allows('view', $inOne))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('view', $loose))->toBeFalse()
        ->and(Gate::forUser($this->user)->allows('view'))->toBeFalse()
        ->and(Gate::forUser($this->user)->allows('publish'))->toBeFalse();
});

it('uses the configured default membership attribute', function (): void {
    config()->set('warden.restrictions.default_attribute', 'owner_id');
    $held = Account::query()->create(['name' => 'Held', 'owner_id' => $this->orgOne->getKey()])->refresh();

    $this->warden->assign('editor')->on($this->orgOne)->to($this->user);

    expect(Gate::forUser($this->user)->allows('edit', $held))->toBeTrue();
});

it('stays strict-mode safe when the membership attribute is missing', function (): void {
    $this->warden->restrictedVia(Account::class, 'missing_column');
    Model::preventAccessingMissingAttributes();

    $this->warden->assign('editor')->on($this->orgOne)->to($this->user);

    expect(Gate::forUser($this->user)->allows('edit', projectIn($this->orgOne)))->toBeFalse();

    Model::preventAccessingMissingAttributes(false);
});

it('surfaces strict mode when the safety valve is off', function (): void {
    config()->set('warden.ownership.strict_mode_safe', false);
    $this->warden->restrictedVia(Account::class, 'missing_column');
    Model::preventAccessingMissingAttributes();

    $this->warden->assign('editor')->on($this->orgOne)->to($this->user);
    $project = projectIn($this->orgOne);
    $fresh = Account::query()->whereKey($project->getKey())->sole();

    try {
        Gate::forUser($this->user)->allows('edit', $fresh);
        $this->fail('Expected MissingAttributeException.');
    } catch (Illuminate\Database\Eloquent\MissingAttributeException $exception) {
        // Opted out: the model's strict mode surfaces.
        expect($exception->getMessage())->toContain('missing_column');
    } finally {
        Model::preventAccessingMissingAttributes(false);
    }
});

it('returns not-a-member quietly when opted out without strict mode', function (): void {
    config()->set('warden.ownership.strict_mode_safe', false);
    $this->warden->restrictedVia(Account::class, 'missing_column');

    $this->warden->assign('editor')->on($this->orgOne)->to($this->user);

    // No strict prevention active: the probe returns null, not an exception.
    expect(Gate::forUser($this->user)->allows('edit', projectIn($this->orgOne)))->toBeFalse();
});

it('rejects closures as per-model restriction attributes', function (): void {
    $this->warden->restrictedVia(fn (): bool => true, 'attribute');
})->throws(ConfigurationException::class, 'context class as a string');

it('fails closed for unhydratable context types', function (): void {
    $this->warden->restrictedVia(fn (): bool => true);

    $this->warden->assign('editor')->to($this->user);
    AssignedRole::query()->update(['restricted_to_type' => 'ghost-type', 'restricted_to_id' => 9]);
    $this->warden->refresh();

    expect(Gate::forUser($this->user)->allows('edit', projectIn($this->orgOne)))->toBeFalse();
});

it('treats half-written restrictions as unusable, never as global', function (): void {
    $this->warden->assign('editor')->on($this->orgOne)->to($this->user);
    AssignedRole::query()->update(['restricted_to_id' => null]);
    $this->warden->refresh();

    expect(Gate::forUser($this->user)->allows('edit', projectIn($this->orgOne)))->toBeFalse()
        ->and(Gate::forUser($this->user)->allows('edit', $this->orgOne))->toBeFalse();

    // The cache engine skips half-written restrictions the same way.
    config()->set('warden.cache.enabled', true);

    expect(Gate::forUser($this->user)->allows('edit', $this->orgOne))->toBeFalse();
});

it('rejects restriction contexts without a usable key', function (): void {
    $keyless = ElPandaPe\Warden\Tests\Fixtures\KeylessAccount::query()
        ->create(['name' => 'Ghost']);

    $this->warden->assign('editor')->on($keyless);
})->throws(ConfigurationException::class, 'usable key');

it('leaves restricted assignments alone when syncing roles', function (): void {
    $this->warden->assign('editor')->on($this->orgOne)->to($this->user);

    // Sync declares the unrestricted set: the context assignment survives,
    // and the requested role is created as an explicit unrestricted one.
    $this->warden->sync($this->user)->roles(['editor']);

    expect(AssignedRole::query()->whereNotNull('restricted_to_id')->count())->toBe(1)
        ->and(AssignedRole::query()->whereNull('restricted_to_id')->count())->toBe(1);

    $this->warden->sync($this->user)->roles([]);

    expect(AssignedRole::query()->whereNotNull('restricted_to_id')->count())->toBe(1)
        ->and(AssignedRole::query()->whereNull('restricted_to_id')->count())->toBe(0)
        ->and(Gate::forUser($this->user)->allows('edit', projectIn($this->orgOne)))->toBeTrue();
});
