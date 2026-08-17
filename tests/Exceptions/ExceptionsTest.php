<?php

declare(strict_types=1);

use ElPandaPe\Bouncer\Bouncer;
use ElPandaPe\Bouncer\Exceptions\ConfigurationException;
use ElPandaPe\Bouncer\Exceptions\PermissionDoesNotExist;
use ElPandaPe\Bouncer\Exceptions\RoleDoesNotExist;
use ElPandaPe\Bouncer\Exceptions\UnauthorizedException;
use ElPandaPe\Bouncer\Tests\Fixtures\Plain;
use ElPandaPe\Bouncer\Tests\Fixtures\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Gate;

use function ElPandaPe\Bouncer\Tests\Database\migrateBouncerTables;

beforeEach(function (): void {
    migrateBouncerTables();

    $this->bouncer = app(Bouncer::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
});

it('throws a typed exception for missing roles', function (): void {
    $this->bouncer->disallow('ghost-role')->to('anything');
})->throws(RoleDoesNotExist::class, 'Role [ghost-role] does not exist.');

it('finds roles by name or fails loudly', function (): void {
    $this->bouncer->assign('admin')->to($this->user);

    expect($this->bouncer->findRole('admin')->getAttribute('name'))->toBe('admin')
        ->and(fn () => $this->bouncer->findRole('ghost'))
        ->toThrow(RoleDoesNotExist::class, 'Role [ghost] does not exist.');
});

it('finds permissions by name or fails loudly', function (): void {
    $this->bouncer->allow($this->user)->to('edit-site');

    expect($this->bouncer->findPermission('edit-site')->getAttribute('name'))->toBe('edit-site')
        ->and(fn () => $this->bouncer->findPermission('ghost'))
        ->toThrow(PermissionDoesNotExist::class, 'Permission [ghost] does not exist.');
});

it('keeps not-found exceptions catchable the Laravel way', function (): void {
    expect(fn () => $this->bouncer->findRole('ghost'))->toThrow(ModelNotFoundException::class);
});

it('reports misconfiguration with a typed exception', function (): void {
    expect(fn () => $this->bouncer->is(new Plain))->toThrow(ConfigurationException::class)
        ->and(fn () => config()->set('bouncer.models.role', Plain::class) || ElPandaPe\Bouncer\Context::resolve()->setModelClass('role', Plain::class))
        ->toThrow(ConfigurationException::class);
});

it('throws an unauthorized exception with the required permission', function (): void {
    $this->actingAs($this->user);

    try {
        $this->bouncer->authorize('publish');
        $this->fail('Expected UnauthorizedException.');
    } catch (UnauthorizedException $exception) {
        expect($exception->getRequiredPermissions())->toBe(['publish'])
            ->and($exception->getRequiredRoles())->toBeEmpty()
            ->and($exception->getMessage())->toBe('This action is unauthorized.');
    }
});

it('names the missing permission only when the leak flag opts in', function (): void {
    config()->set('bouncer.exceptions.display_permission_in_exception', true);
    $this->actingAs($this->user);

    expect(function () {
        $this->bouncer->authorize('publish');
        $this->fail('Expected UnauthorizedException.');
    })->toThrow(UnauthorizedException::class, 'Missing required permission: publish.');
});

it('translates denial messages to spanish', function (): void {
    config()->set('bouncer.exceptions.display_permission_in_exception', true);
    App::setLocale('es');
    $this->actingAs($this->user);

    expect(fn () => $this->bouncer->authorize('publish'))
        ->toThrow(UnauthorizedException::class, 'Falta el permiso requerido: publish.');
});

it('preserves custom policy denial messages', function (): void {
    Gate::define('special', fn (User $user): Response => Response::deny('Custom nope'));
    $this->actingAs($this->user);

    expect(fn () => $this->bouncer->authorize('special'))
        ->toThrow(UnauthorizedException::class, 'Custom nope');
});

it('preserves the gate response, status and code through the wrap', function (): void {
    Gate::define('teapot', fn (User $user): Response => Response::denyWithStatus(418, 'Short and stout', 7));
    $this->actingAs($this->user);

    try {
        $this->bouncer->authorize('teapot');
        $this->fail('Expected UnauthorizedException.');
    } catch (UnauthorizedException $exception) {
        expect($exception->hasStatus())->toBeTrue()
            ->and($exception->status())->toBe(418)
            ->and($exception->getMessage())->toBe('Short and stout')
            ->and($exception->getCode())->toBe(7)
            ->and($exception->response())->toBeInstanceOf(Response::class);
    }
});

it('translates the generic denial even when laravel supplies its default', function (): void {
    App::setLocale('es');
    $this->actingAs($this->user);

    expect(fn () => $this->bouncer->authorize('missing'))
        ->toThrow(UnauthorizedException::class, 'Esta acción no está autorizada.');
});

it('returns the gate response when authorization passes', function (): void {
    $this->bouncer->allow($this->user)->to('publish');
    $this->actingAs($this->user);

    expect($this->bouncer->authorize('publish'))->toBeInstanceOf(Response::class);
});

it('builds role-flavored unauthorized exceptions for future consumers', function (): void {
    $generic = UnauthorizedException::forRoles(['admin']);

    config()->set('bouncer.exceptions.display_role_in_exception', true);
    $named = UnauthorizedException::forRoles(['admin', 'editor']);

    expect($generic->getMessage())->toBe('This action is unauthorized.')
        ->and($generic->getRequiredRoles())->toBe(['admin'])
        ->and($named->getMessage())->toBe('Missing required role: admin, editor.')
        ->and($named->getRequiredPermissions())->toBeEmpty();
});
