<?php

declare(strict_types=1);

use ElPandaPe\Bouncer\Bouncer;
use ElPandaPe\Bouncer\BouncerServiceProvider;
use ElPandaPe\Bouncer\Exceptions\UnauthorizedException;
use ElPandaPe\Bouncer\Http\Middleware\RequiresPermission;
use ElPandaPe\Bouncer\Http\Middleware\RequiresRole;
use ElPandaPe\Bouncer\Tests\Fixtures\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;

use function ElPandaPe\Bouncer\Tests\Database\migrateBouncerTables;

beforeEach(function (): void {
    migrateBouncerTables();

    $this->bouncer = app(Bouncer::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
});

function requestAs(?User $user): Request
{
    $request = Request::create('/');
    $request->setUserResolver(fn (): ?User => $user);

    return $request;
}

it('registers aliases and directives only when opted in', function (): void {
    expect(app(Router::class)->getMiddleware())->not->toHaveKey('bouncer.role');

    config()->set('bouncer.register_middleware_aliases', true);
    config()->set('bouncer.register_blade_directives', true);
    new BouncerServiceProvider(app())->boot();

    expect(app(Router::class)->getMiddleware())
        ->toHaveKeys(['bouncer.role', 'bouncer.permission']);
});

it('guards routes by role, any-of', function (): void {
    $this->bouncer->assign('editor')->to($this->user);

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
    $this->bouncer->allow($this->user)->to('edit-site');

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
    config()->set('bouncer.register_blade_directives', true);
    new BouncerServiceProvider(app())->boot();

    $this->bouncer->allow($this->user)->to('publish');
    $this->bouncer->forbid($this->user)->to('publish');
    $this->actingAs($this->user);

    $template = '@forbidden("publish") BLOCKED @else FREE @endforbidden';

    expect(trim(Blade::render($template)))->toBe('BLOCKED');

    $this->bouncer->unforbid($this->user)->to('publish');

    // Merely lacking a permission is not an explicit denial.
    expect(trim(Blade::render('@forbidden("missing") BLOCKED @else FREE @endforbidden')))->toBe('FREE');
});

it('stays quiet for guests in the forbidden directive', function (): void {
    config()->set('bouncer.register_blade_directives', true);
    new BouncerServiceProvider(app())->boot();

    expect(trim(Blade::render('@forbidden("anything") BLOCKED @else FREE @endforbidden')))->toBe('FREE');
});

it('fails closed when middleware is registered without parameters', function (): void {
    $this->bouncer->assign('admin')->to($this->user);
    $this->bouncer->allow($this->user)->to('edit-site');

    expect(fn () => new RequiresRole()->handle(requestAs($this->user), fn (): Response => new Response('ok')))
        ->toThrow(UnauthorizedException::class)
        ->and(fn () => new RequiresPermission()->handle(requestAs($this->user), fn (): Response => new Response('ok')))
        ->toThrow(UnauthorizedException::class);
});
