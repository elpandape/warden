<?php

declare(strict_types=1);

use ElPandaPe\Bouncer\Bouncer;
use ElPandaPe\Bouncer\Events\RoleAssigned;
use ElPandaPe\Bouncer\Events\RoleRetracted;
use ElPandaPe\Bouncer\Exceptions\ConfigurationException;
use ElPandaPe\Bouncer\Models\AssignedRole;
use ElPandaPe\Bouncer\Tests\Fixtures\Account;
use ElPandaPe\Bouncer\Tests\Fixtures\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

use function ElPandaPe\Bouncer\Tests\Database\migrateBouncerTables;

beforeEach(function (): void {
    migrateBouncerTables();

    $this->bouncer = app(Bouncer::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
    $this->orgOne = Account::query()->create(['name' => 'Org One'])->refresh();
    $this->orgTwo = Account::query()->create(['name' => 'Org Two'])->refresh();

    $this->bouncer->allow('editor')->to('edit', Account::class);
});

function projectIn(Account $org): Account
{
    return Account::query()->create(['name' => 'Project', 'account_id' => $org->getKey()])->refresh();
}

it('holds the same role in several contexts at once', function (): void {
    $this->bouncer->assign('editor')->on($this->orgOne)->to($this->user);
    $this->bouncer->assign('editor')->on($this->orgTwo)->to($this->user);

    $inOne = projectIn($this->orgOne);
    $orphan = Account::query()->create(['name' => 'Loose'])->refresh();

    expect(AssignedRole::query()->count())->toBe(2)
        ->and(Gate::forUser($this->user)->allows('edit', $inOne))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('edit', projectIn($this->orgTwo)))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('edit', $orphan))->toBeFalse();
});

it('authorizes the context model itself', function (): void {
    $this->bouncer->assign('editor')->on($this->orgOne)->to($this->user);

    expect(Gate::forUser($this->user)->allows('edit', $this->orgOne))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('edit', $this->orgTwo))->toBeFalse();
});

it('contributes nothing to instance-less checks', function (): void {
    $this->bouncer->allow('editor')->to('publish');
    $this->bouncer->assign('editor')->on($this->orgOne)->to($this->user);

    expect(Gate::forUser($this->user)->allows('publish'))->toBeFalse()
        ->and(Gate::forUser($this->user)->allows('edit', Account::class))->toBeFalse();

    // An unrestricted assignment of the same role restores the simple check.
    $this->bouncer->assign('editor')->to($this->user);

    expect(Gate::forUser($this->user)->allows('publish'))->toBeTrue();
});

it('retracts one context or every assignment', function (): void {
    $this->bouncer->assign('editor')->on($this->orgOne)->to($this->user);
    $this->bouncer->assign('editor')->on($this->orgTwo)->to($this->user);

    $this->bouncer->retract('editor')->on($this->orgOne)->from($this->user);

    expect(AssignedRole::query()->count())->toBe(1)
        ->and(Gate::forUser($this->user)->allows('edit', projectIn($this->orgTwo)))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('edit', projectIn($this->orgOne)))->toBeFalse();

    $this->bouncer->retract('editor')->from($this->user);

    expect(AssignedRole::query()->count())->toBe(0);
});

it('resolves membership through configuration', function (): void {
    $this->bouncer->restrictedVia(Account::class, 'owner_id');
    $owned = Account::query()->create(['name' => 'Held', 'owner_id' => $this->orgOne->getKey()])->refresh();

    $this->bouncer->assign('editor')->on($this->orgOne)->to($this->user);

    expect(Gate::forUser($this->user)->allows('edit', $owned))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('edit', projectIn($this->orgOne)))->toBeFalse();
});

it('resolves membership through a global closure', function (): void {
    $this->bouncer->restrictedVia(
        fn (Model $entity, Model $context): bool => $entity->getAttribute('name') === 'Chosen',
    );

    $chosen = Account::query()->create(['name' => 'Chosen'])->refresh();
    $other = Account::query()->create(['name' => 'Other'])->refresh();

    $this->bouncer->assign('editor')->on($this->orgOne)->to($this->user);

    expect(Gate::forUser($this->user)->allows('edit', $chosen))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('edit', $other))->toBeFalse();
});

it('rejects late or unsaved restriction contexts', function (): void {
    expect(fn () => $this->bouncer->assign('editor')->to($this->user)->on($this->orgOne))
        ->toThrow(ConfigurationException::class, 'before to()')
        ->and(fn () => $this->bouncer->assign('editor')->on(new Account(['name' => 'Ghost'])))
        ->toThrow(ConfigurationException::class, 'saved model')
        ->and(fn () => $this->bouncer->retract('editor')->from($this->user)->on($this->orgOne))
        ->toThrow(ConfigurationException::class, 'before from()');
});

it('composes with tenancy', function (): void {
    $this->bouncer->tenant()->to(1);
    $this->bouncer->allow('editor')->to('edit', Account::class);
    $this->bouncer->assign('editor')->on($this->orgOne)->to($this->user);

    $project = projectIn($this->orgOne);

    expect(Gate::forUser($this->user)->allows('edit', $project))->toBeTrue();

    $this->bouncer->tenant()->to(2);

    expect(Gate::forUser($this->user)->allows('edit', $project))->toBeFalse();
});

it('announces the restriction context in role events', function (): void {
    Event::fake([RoleAssigned::class, RoleRetracted::class]);

    $this->bouncer->assign('editor')->on($this->orgOne)->to($this->user);
    $this->bouncer->retract('editor')->on($this->orgOne)->from($this->user);

    Event::assertDispatched(RoleAssigned::class, fn (RoleAssigned $event): bool => $event->restrictedTo?->is($this->orgOne) === true);
    Event::assertDispatched(RoleRetracted::class, fn (RoleRetracted $event): bool => $event->restrictedTo?->is($this->orgOne) === true);
});

it('keeps role membership checks unaware of restrictions', function (): void {
    $this->bouncer->assign('editor')->on($this->orgOne)->to($this->user);

    // isA answers "holds the role", context or not — documented semantics.
    expect($this->user->isAn('editor'))->toBeTrue();
});

it('serves restricted roles and constraints from the cache engine', function (): void {
    config()->set('bouncer.cache.enabled', true);

    $this->bouncer->assign('editor')->on($this->orgOne)->to($this->user);
    $this->bouncer->allow($this->user)->to('view', Account::class)->where('name', 'Project');
    $this->bouncer->allow($this->user)->to('publish')->where('name', 'X');

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
    config()->set('bouncer.restrictions.default_attribute', 'owner_id');
    $held = Account::query()->create(['name' => 'Held', 'owner_id' => $this->orgOne->getKey()])->refresh();

    $this->bouncer->assign('editor')->on($this->orgOne)->to($this->user);

    expect(Gate::forUser($this->user)->allows('edit', $held))->toBeTrue();
});

it('stays strict-mode safe when the membership attribute is missing', function (): void {
    $this->bouncer->restrictedVia(Account::class, 'missing_column');
    Model::preventAccessingMissingAttributes();

    $this->bouncer->assign('editor')->on($this->orgOne)->to($this->user);

    expect(Gate::forUser($this->user)->allows('edit', projectIn($this->orgOne)))->toBeFalse();

    Model::preventAccessingMissingAttributes(false);
});

it('surfaces strict mode when the safety valve is off', function (): void {
    config()->set('bouncer.ownership.strict_mode_safe', false);
    $this->bouncer->restrictedVia(Account::class, 'missing_column');
    Model::preventAccessingMissingAttributes();

    $this->bouncer->assign('editor')->on($this->orgOne)->to($this->user);
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
    config()->set('bouncer.ownership.strict_mode_safe', false);
    $this->bouncer->restrictedVia(Account::class, 'missing_column');

    $this->bouncer->assign('editor')->on($this->orgOne)->to($this->user);

    // No strict prevention active: the probe returns null, not an exception.
    expect(Gate::forUser($this->user)->allows('edit', projectIn($this->orgOne)))->toBeFalse();
});

it('rejects closures as per-model restriction attributes', function (): void {
    $this->bouncer->restrictedVia(fn (): bool => true, 'attribute');
})->throws(ConfigurationException::class, 'context class as a string');

it('fails closed for unhydratable context types', function (): void {
    $this->bouncer->restrictedVia(fn (): bool => true);

    $this->bouncer->assign('editor')->to($this->user);
    AssignedRole::query()->update(['restricted_to_type' => 'ghost-type', 'restricted_to_id' => 9]);
    $this->bouncer->refresh();

    expect(Gate::forUser($this->user)->allows('edit', projectIn($this->orgOne)))->toBeFalse();
});

it('treats half-written restrictions as unusable, never as global', function (): void {
    $this->bouncer->assign('editor')->on($this->orgOne)->to($this->user);
    AssignedRole::query()->update(['restricted_to_id' => null]);
    $this->bouncer->refresh();

    expect(Gate::forUser($this->user)->allows('edit', projectIn($this->orgOne)))->toBeFalse()
        ->and(Gate::forUser($this->user)->allows('edit', $this->orgOne))->toBeFalse();

    // The cache engine skips half-written restrictions the same way.
    config()->set('bouncer.cache.enabled', true);

    expect(Gate::forUser($this->user)->allows('edit', $this->orgOne))->toBeFalse();
});

it('rejects restriction contexts without a usable key', function (): void {
    $keyless = ElPandaPe\Bouncer\Tests\Fixtures\KeylessAccount::query()
        ->create(['name' => 'Ghost']);

    $this->bouncer->assign('editor')->on($keyless);
})->throws(ConfigurationException::class, 'usable key');

it('leaves restricted assignments alone when syncing roles', function (): void {
    $this->bouncer->assign('editor')->on($this->orgOne)->to($this->user);

    // Sync declares the unrestricted set: the context assignment survives,
    // and the requested role is created as an explicit unrestricted one.
    $this->bouncer->sync($this->user)->roles(['editor']);

    expect(AssignedRole::query()->whereNotNull('restricted_to_id')->count())->toBe(1)
        ->and(AssignedRole::query()->whereNull('restricted_to_id')->count())->toBe(1);

    $this->bouncer->sync($this->user)->roles([]);

    expect(AssignedRole::query()->whereNotNull('restricted_to_id')->count())->toBe(1)
        ->and(AssignedRole::query()->whereNull('restricted_to_id')->count())->toBe(0)
        ->and(Gate::forUser($this->user)->allows('edit', projectIn($this->orgOne)))->toBeTrue();
});
