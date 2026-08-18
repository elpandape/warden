<?php

declare(strict_types=1);

use ElPandaPe\Warden\Exceptions\UnauthorizedException;
use ElPandaPe\Warden\Http\Middleware\RequiresPermission;
use ElPandaPe\Warden\Http\Middleware\RequiresRole;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use ElPandaPe\Warden\WardenServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

beforeEach(function (): void {
    migrateWardenTables();

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
});

function requestAs(?User $user): Request
{
    $request = Request::create('/');
    $request->setUserResolver(fn (): ?User => $user);

    return $request;
}

it('registers aliases and directives only when opted in', function (): void {
    expect(app(Router::class)->getMiddleware())->not->toHaveKey('warden.role');

    config()->set('warden.register_middleware_aliases', true);
    config()->set('warden.register_blade_directives', true);
    new WardenServiceProvider(app())->boot();

    expect(app(Router::class)->getMiddleware())
        ->toHaveKeys(['warden.role', 'warden.permission']);
});

it('guards routes by role, any-of', function (): void {
    $this->warden->assign('editor')->to($this->user);

    $middleware = new RequiresRole;
    $response = $middleware->handle(requestAs($this->user), fn (): Response => new Response('ok'), 'admin', 'editor');

    expect($response->getContent())->toBe('ok');

    try {
        $middleware->handle(requestAs($this->user), fn (): Response => new Response('ok'), 'admin');
        $this->fail('Expected UnauthorizedException.');
    } catch (UnauthorizedException $exception) {
        expect($exception->getRequiredRoles())->toBe(['admin']);
    }

    expect(fn () => $middleware->handle(requestAs(null), fn (): Response => new Response('ok'), 'admin'))
        ->toThrow(UnauthorizedException::class);
});

it('guards routes by permission, all-of', function (): void {
    $this->warden->allow($this->user)->to('edit-site');

    $middleware = new RequiresPermission;
    $response = $middleware->handle(requestAs($this->user), fn (): Response => new Response('ok'), 'edit-site');

    expect($response->getContent())->toBe('ok');

    try {
        $middleware->handle(requestAs($this->user), fn (): Response => new Response('ok'), 'edit-site', 'publish');
        $this->fail('Expected UnauthorizedException.');
    } catch (UnauthorizedException $exception) {
        expect($exception->getRequiredPermissions())->toBe(['edit-site', 'publish']);
    }

    expect(fn () => $middleware->handle(requestAs(null), fn (): Response => new Response('ok'), 'edit-site'))
        ->toThrow(UnauthorizedException::class);
});

it('renders the forbidden directive for explicit denials only', function (): void {
    config()->set('warden.register_blade_directives', true);
    new WardenServiceProvider(app())->boot();

    $this->warden->allow($this->user)->to('publish');
    $this->warden->forbid($this->user)->to('publish');
    $this->actingAs($this->user);

    $template = '@forbidden("publish") BLOCKED @else FREE @endforbidden';

    expect(trim(Blade::render($template)))->toBe('BLOCKED');

    $this->warden->unforbid($this->user)->to('publish');

    // Merely lacking a permission is not an explicit denial.
    expect(trim(Blade::render('@forbidden("missing") BLOCKED @else FREE @endforbidden')))->toBe('FREE');
});

it('stays quiet for guests in the forbidden directive', function (): void {
    config()->set('warden.register_blade_directives', true);
    new WardenServiceProvider(app())->boot();

    expect(trim(Blade::render('@forbidden("anything") BLOCKED @else FREE @endforbidden')))->toBe('FREE');
});

it('fails closed when middleware is registered without parameters', function (): void {
    $this->warden->assign('admin')->to($this->user);
    $this->warden->allow($this->user)->to('edit-site');

    expect(fn () => new RequiresRole()->handle(requestAs($this->user), fn (): Response => new Response('ok')))
        ->toThrow(UnauthorizedException::class)
        ->and(fn () => new RequiresPermission()->handle(requestAs($this->user), fn (): Response => new Response('ok')))
        ->toThrow(UnauthorizedException::class);
});
