<?php

declare(strict_types=1);

use ElPandaPe\Warden\Checks\Resolvers\CacheInvalidations;
use ElPandaPe\Warden\Checks\Resolvers\CacheKeyVersioner;
use ElPandaPe\Warden\Facades\Warden as WardenFacade;
use ElPandaPe\Warden\Support\Operations;
use ElPandaPe\Warden\Warden;
use ElPandaPe\Warden\WardenServiceProvider;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Symfony\Component\Uid\Ulid;

it('opens an operation under a fresh upper-case ULID from Str::ulid()', function (): void {
    $frozen = Str::freezeUlids();

    $id = app(Operations::class)->during(fn (string $id): string => $id);

    expect($id)->toBe((string) $frozen)
        ->and(Str::isUlid($id))->toBeTrue()
        ->and($id)->toHaveLength(26)
        ->and($id)->toBe(strtoupper($id));
});

it('has no operation outside one', function (): void {
    $operations = app(Operations::class);
    $before = $operations->current();

    [$id, $inside] = $operations->during(fn (string $id): array => [$id, $operations->current()]);

    expect($before)->toBeNull()
        ->and($inside)->toBe($id)
        ->and($operations->current())->toBeNull();
});

it('hands a nested call the outer id and ignores its resume', function (): void {
    $operations = app(Operations::class);

    [$outer, $inner, $afterInner] = $operations->during(fn (string $outer): array => [
        $outer,
        $operations->during(fn (string $inner): string => $inner, '01ARZ3NDEKTSV4RRFFQ69G5FAV'),
        $operations->current(),
    ]);

    expect($inner)->toBe($outer)
        ->and($afterInner)->toBe($outer)
        ->and($operations->current())->toBeNull();
});

it('mints one id per outermost operation, however deep it nests', function (): void {
    $minted = 0;
    Str::createUlidsUsing(function () use (&$minted): Ulid {
        $minted++;

        return new Ulid;
    });

    $operations = app(Operations::class);
    $operations->during(fn (): mixed => $operations->during(fn (): mixed => $operations->during(fn (): null => null)));

    expect($minted)->toBe(1);
});

it('resumes the given id when no operation is open', function (): void {
    $operations = app(Operations::class);

    expect($operations->during(fn (string $id): string => $id, '01ARZ3NDEKTSV4RRFFQ69G5FAV'))->toBe('01ARZ3NDEKTSV4RRFFQ69G5FAV')
        ->and($operations->current())->toBeNull();
});

it('gives each operation in a row an id of its own', function (): void {
    $first = Str::ulid();
    $second = Str::ulid();
    Str::createUlidsUsingSequence([$first, $second]);
    $operations = app(Operations::class);

    expect($operations->during(fn (string $id): string => $id))->toBe((string) $first)
        ->and($operations->during(fn (string $id): string => $id))->toBe((string) $second);
});

it('closes the operation a throw leaves, and the next one mints a fresh id', function (): void {
    $first = Str::ulid();
    $second = Str::ulid();
    Str::createUlidsUsingSequence([$first, $second]);
    $operations = app(Operations::class);
    $failure = new RuntimeException('listener failed');

    expect(fn (): mixed => $operations->during(fn (): mixed => $operations->during(function () use ($failure): never {
        throw $failure;
    })))->toThrow(fn (RuntimeException $thrown): mixed => expect($thrown)->toBe($failure))
        ->and($operations->current())->toBeNull()
        ->and($operations->during(fn (string $id): string => $id))->toBe((string) $second);
});

it('runs the callback of Warden::operation() inside one operation and returns its value', function (): void {
    $frozen = Str::freezeUlids();

    $seen = app(Warden::class)->operation(fn (string $id): array => [$id, app(Operations::class)->current()]);

    expect($seen)->toBe([(string) $frozen, (string) $frozen])
        ->and(app(Operations::class)->current())->toBeNull();
});

it('joins the outer operation when Warden::operation() nests', function (): void {
    [$outer, $inner] = WardenFacade::operation(fn (string $outer): array => [
        $outer,
        WardenFacade::operation(fn (string $inner): string => $inner),
    ]);

    expect($inner)->toBe($outer);
});

it('opens no database transaction', function (): void {
    expect(app(Warden::class)->operation(fn (): int => DB::transactionLevel()))->toBe(0)
        ->and(DB::transaction(fn (): int => app(Warden::class)->operation(fn (): int => DB::transactionLevel())))->toBe(1);
});

it('defers no dispatch while it is open', function (): void {
    $heard = [];
    Event::listen('warden.probe', function () use (&$heard): void {
        $heard[] = 'listener';
    });

    app(Warden::class)->operation(function () use (&$heard): void {
        event('warden.probe');
        $heard[] = 'after dispatch';
    });

    expect($heard)->toBe(['listener', 'after dispatch']);
});

it('holds back no cache bump while it is open', function (): void {
    $versioner = app(CacheKeyVersioner::class);
    $before = $versioner->segment();

    $inside = app(Warden::class)->operation(function () use ($versioner): string {
        app(CacheInvalidations::class)->mark(null);

        return $versioner->segment();
    });

    expect($inside)->not->toBe($before);
});

it('starts outside any operation in a new scoped container lifecycle', function (): void {
    $first = app(Operations::class);

    [$outer, $afterReset, $fresh] = $first->during(function (string $outer): array {
        app()->forgetScopedInstances();

        return [$outer, app(Operations::class)->current(), app(Operations::class)->during(fn (string $id): string => $id)];
    });

    expect(app(Operations::class))->not->toBe($first)
        ->and($afterReset)->toBeNull()
        ->and($fresh)->not->toBe($outer);
});

it('keeps one instance across container lifecycles when the reset opt-out is set', function (): void {
    $container = new Container;
    $config = new ConfigRepository(['warden' => ['octane' => ['register_reset_listener' => false]]]);
    $container->instance('config', $config);
    $container->instance(Repository::class, $config);
    new WardenServiceProvider($container)->register();

    $first = $container->make(Operations::class);
    $container->forgetScopedInstances();

    expect($container->make(Operations::class))->toBe($first);
});
