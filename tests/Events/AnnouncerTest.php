<?php

declare(strict_types=1);

use ElPandaPe\Warden\Checks\Resolvers\CacheInvalidations;
use ElPandaPe\Warden\Checks\Resolvers\CacheKeyVersioner;
use ElPandaPe\Warden\Events\PermissionGranted;
use ElPandaPe\Warden\Events\RoleAssigned;
use ElPandaPe\Warden\Events\RoleCreated;
use ElPandaPe\Warden\Events\RoleRetracted;
use ElPandaPe\Warden\Models\Role;
use ElPandaPe\Warden\Support\Announcer;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Support\Facades\Event;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

beforeEach(function (): void {
    migrateWardenTables();
    config()->set('warden.cache.enabled', true);

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Ana']);
});

it('lets a listener of an assignment see the access the role brings', function (): void {
    $this->warden->allow('editor')->to('publish');

    expect($this->user->can('publish'))->toBeFalse();

    $seen = null;
    Event::listen(RoleAssigned::class, function () use (&$seen): void {
        $seen = $this->user->can('publish');
    });

    $this->warden->assign('editor')->to($this->user);

    expect($seen)->toBeTrue();
});

it('lets a listener of a retraction see the access the role took away', function (): void {
    $this->warden->allow('editor')->to('publish');
    $this->warden->assign('editor')->to($this->user);

    expect($this->user->can('publish'))->toBeTrue();

    $seen = null;
    Event::listen(RoleRetracted::class, function () use (&$seen): void {
        $seen = $this->user->can('publish');
    });

    $this->warden->retract('editor')->from($this->user);

    expect($seen)->toBeFalse();
});

it('lets a listener of a grant see the access it gives', function (): void {
    expect($this->user->can('publish'))->toBeFalse();

    $seen = null;
    Event::listen(PermissionGranted::class, function () use (&$seen): void {
        $seen = $this->user->can('publish');
    });

    $this->warden->allow($this->user)->to('publish');

    expect($seen)->toBeTrue();
});

it('applies what an open boundary holds without closing it', function (): void {
    $invalidations = app(CacheInvalidations::class);
    $versioner = app(CacheKeyVersioner::class);

    $invalidations->during(function () use ($invalidations, $versioner): void {
        $before = $versioner->segment();

        $invalidations->mark(null);
        $held = $versioner->segment();

        $invalidations->flush();
        $flushed = $versioner->segment();

        $invalidations->mark(null);

        expect($held)->toBe($before)
            ->and($flushed)->not->toBe($before)
            ->and($versioner->segment())->toBe($flushed);
    });
});

it('announces nothing and applies nothing while events are off', function (): void {
    config()->set('warden.events_enabled', false);
    Event::fake([RoleCreated::class]);

    $invalidations = app(CacheInvalidations::class);
    $versioner = app(CacheKeyVersioner::class);

    $invalidations->during(function () use ($invalidations, $versioner): void {
        $invalidations->mark(null);
        $held = $versioner->segment();

        Announcer::announce(new RoleCreated(new Role));

        expect($versioner->segment())->toBe($held);
    });

    Event::assertNotDispatched(RoleCreated::class);
});
