<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ElPandaPe\Warden\Events\AssignmentChange;
use ElPandaPe\Warden\Events\AssignmentRemoval;
use ElPandaPe\Warden\Events\GrantChange;
use ElPandaPe\Warden\Events\GrantRemoval;
use ElPandaPe\Warden\Models\Permission;
use ElPandaPe\Warden\Models\Role;
use ElPandaPe\Warden\Tests\Fixtures\Account;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

beforeEach(function (): void {
    migrateWardenTables();

    $this->until = CarbonImmutable::parse('2026-12-31 23:59:59');
    $this->before = CarbonImmutable::parse('2026-06-30 12:00:00');
});

it('describes a grant row a write moved, with the dates before and after', function (): void {
    $permission = Permission::query()->create(['name' => 'publish']);

    $change = new GrantChange(permission: $permission, created: false, expiresAt: $this->until, previousExpiresAt: $this->before);

    expect($change->permission)->toBe($permission)
        ->and($change->created)->toBeFalse()
        ->and($change->expiresAt)->toBe($this->until)
        ->and($change->previousExpiresAt)->toBe($this->before);
});

it('describes an assignment row a write created, with the date it was born with', function (): void {
    $role = Role::query()->create(['name' => 'editor']);

    $change = new AssignmentChange(role: $role, created: true, expiresAt: $this->until, previousExpiresAt: null);

    expect($change->role)->toBe($role)
        ->and($change->created)->toBeTrue()
        ->and($change->expiresAt)->toBe($this->until)
        ->and($change->previousExpiresAt)->toBeNull();
});

it('describes a grant row a removal deleted, with the date it still carried', function (): void {
    $permission = Permission::query()->create(['name' => 'publish']);

    $removal = new GrantRemoval(permission: $permission, expiresAt: $this->until);

    expect($removal->permission)->toBe($permission)
        ->and($removal->expiresAt)->toBe($this->until);
});

it('describes an assignment row a removal deleted, with its context and date', function (): void {
    $role = Role::query()->create(['name' => 'editor']);
    $acme = Account::query()->create(['name' => 'Acme']);

    $removal = new AssignmentRemoval(role: $role, restrictedTo: $acme, expiresAt: null);

    expect($removal->role)->toBe($role)
        ->and($removal->restrictedTo)->toBe($acme)
        ->and($removal->expiresAt)->toBeNull();
});

it('travels through a queue by value and outlives the rows it names', function (): void {
    $role = Role::query()->create(['name' => 'editor']);
    $acme = Account::query()->create(['name' => 'Acme']);

    $payload = serialize([new AssignmentRemoval(role: $role, restrictedTo: $acme, expiresAt: $this->until)]);

    $role->delete();
    $acme->delete();

    [$restored] = unserialize($payload);

    expect($restored)->toBeInstanceOf(AssignmentRemoval::class)
        ->and($restored->role->getAttribute('name'))->toBe('editor')
        ->and($restored->restrictedTo?->getAttribute('name'))->toBe('Acme')
        ->and($restored->expiresAt?->toDateTimeString())->toBe('2026-12-31 23:59:59');
});
