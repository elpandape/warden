<?php

declare(strict_types=1);

use ElPandaPe\Warden\Exceptions\ConfigurationException;
use ElPandaPe\Warden\Testing\WardenFake;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

beforeEach(function (): void {
    migrateWardenTables();

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
    $this->other = User::query()->create(['name' => 'Ada']);
    $this->mine = Account::query()->create(['name' => 'Mine', 'user_id' => $this->user->getKey()])->refresh();
    $this->theirs = Account::query()->create(['name' => 'Theirs', 'user_id' => $this->other->getKey()])->refresh();
});

it('answers what the database engine answers', function (array $scenario): void {
    $tenant = $scenario['tenant'] ?? null;

    if ($tenant !== null) {
        $this->warden->tenant()->to($tenant);
    }

    $scenario['engine']($this->warden, $this->user, $this->mine);
    $this->warden->refresh();

    $engine = Gate::forUser($this->user)->allows(...$scenario['check']($this->mine, $this->theirs));

    $fake = $this->warden->fake();
    $scenario['fake']($fake, $this->user, $this->mine);

    $scripted = Gate::forUser($this->user)->allows(...$scenario['check']($this->mine, $this->theirs));

    expect($scripted)->toBe($engine);
})->with([
    'a grant still inside its end date' => [[
        'engine' => fn (Warden $w, User $u, Account $a) => $w->allow($u)->until(Carbon::parse('2027-01-01 00:00:00'))->to('publish'),
        'fake' => fn (WardenFake $f, User $u, Account $a) => $f->allow('publish')->for($u)->until(Carbon::parse('2027-01-01 00:00:00')),
        'check' => fn (Account $mine, Account $theirs): array => ['publish'],
    ]],
    'a grant past its end date' => [[
        'engine' => fn (Warden $w, User $u, Account $a) => $w->allow($u)->until(Carbon::parse('2020-01-01 00:00:00'))->to('publish'),
        'fake' => fn (WardenFake $f, User $u, Account $a) => $f->allow('publish')->for($u)->until(Carbon::parse('2020-01-01 00:00:00')),
        'check' => fn (Account $mine, Account $theirs): array => ['publish'],
    ]],
    'a grant at the exact instant it names' => [[
        'engine' => fn (Warden $w, User $u, Account $a) => $w->allow($u)->until(Carbon::now())->to('publish'),
        'fake' => fn (WardenFake $f, User $u, Account $a) => $f->allow('publish')->for($u)->until(Carbon::now()),
        'check' => fn (Account $mine, Account $theirs): array => ['publish'],
    ]],
    'a grant held by another authority' => [[
        'engine' => fn (Warden $w, User $u, Account $a) => $w->allow(User::query()->find(2))->to('publish'),
        'fake' => fn (WardenFake $f, User $u, Account $a) => $f->allow('publish')->for(User::query()->find(2)),
        'check' => fn (Account $mine, Account $theirs): array => ['publish'],
    ]],
    'a grant held by the checked authority' => [[
        'engine' => fn (Warden $w, User $u, Account $a) => $w->allow($u)->to('publish'),
        'fake' => fn (WardenFake $f, User $u, Account $a) => $f->allow('publish')->for($u),
        'check' => fn (Account $mine, Account $theirs): array => ['publish'],
    ]],
    'the wildcard permission answering a named check' => [[
        'engine' => fn (Warden $w, User $u, Account $a) => $w->allow($u)->everything(),
        'fake' => fn (WardenFake $f, User $u, Account $a) => $f->allow('*', '*'),
        'check' => fn (Account $mine, Account $theirs): array => ['publish', $mine],
    ]],
    'a class-wide grant answering an instance check' => [[
        'engine' => fn (Warden $w, User $u, Account $a) => $w->allow($u)->to('edit', Account::class),
        'fake' => fn (WardenFake $f, User $u, Account $a) => $f->allow('edit', Account::class),
        'check' => fn (Account $mine, Account $theirs): array => ['edit', $theirs],
    ]],
    'a class-wide grant answering an entity-less check' => [[
        'engine' => fn (Warden $w, User $u, Account $a) => $w->allow($u)->to('edit', Account::class),
        'fake' => fn (WardenFake $f, User $u, Account $a) => $f->allow('edit', Account::class),
        'check' => fn (Account $mine, Account $theirs): array => ['edit'],
    ]],
    'an instance grant answering another instance' => [[
        'engine' => fn (Warden $w, User $u, Account $a) => $w->allow($u)->to('edit', $a),
        'fake' => fn (WardenFake $f, User $u, Account $a) => $f->allow('edit', $a),
        'check' => fn (Account $mine, Account $theirs): array => ['edit', $theirs],
    ]],
    'an ownership grant reaching what the authority owns' => [[
        'engine' => fn (Warden $w, User $u, Account $a) => $w->allow($u)->toOwn(Account::class)->to('edit'),
        'fake' => fn (WardenFake $f, User $u, Account $a) => $f->allow('edit', Account::class)->owned(),
        'check' => fn (Account $mine, Account $theirs): array => ['edit', $mine],
    ]],
    'an ownership grant reaching what it does not own' => [[
        'engine' => fn (Warden $w, User $u, Account $a) => $w->allow($u)->toOwn(Account::class)->to('edit'),
        'fake' => fn (WardenFake $f, User $u, Account $a) => $f->allow('edit', Account::class)->owned(),
        'check' => fn (Account $mine, Account $theirs): array => ['edit', $theirs],
    ]],
    'a condition the entity satisfies' => [[
        'engine' => fn (Warden $w, User $u, Account $a) => $w->allow($u)->to('edit', Account::class)->where('name', '=', 'Mine'),
        'fake' => fn (WardenFake $f, User $u, Account $a) => $f->allow('edit', Account::class)->where('name', '=', 'Mine'),
        'check' => fn (Account $mine, Account $theirs): array => ['edit', $mine],
    ]],
    'a condition the entity fails' => [[
        'engine' => fn (Warden $w, User $u, Account $a) => $w->allow($u)->to('edit', Account::class)->where('name', '=', 'Mine'),
        'fake' => fn (WardenFake $f, User $u, Account $a) => $f->allow('edit', Account::class)->where('name', '=', 'Mine'),
        'check' => fn (Account $mine, Account $theirs): array => ['edit', $theirs],
    ]],
    'a condition against an entity-less check' => [[
        'engine' => fn (Warden $w, User $u, Account $a) => $w->allow($u)->to('edit', Account::class)->where('name', '=', 'Mine'),
        'fake' => fn (WardenFake $f, User $u, Account $a) => $f->allow('edit', Account::class)->where('name', '=', 'Mine'),
        'check' => fn (Account $mine, Account $theirs): array => ['edit'],
    ]],
    'a forbid beside a grant' => [[
        'engine' => function (Warden $w, User $u, Account $a): void {
            $w->allow($u)->to('publish');
            $w->forbid($u)->to('publish');
        },
        'fake' => fn (WardenFake $f, User $u, Account $a) => $f->allow('publish')->forbid('publish'),
        'check' => fn (Account $mine, Account $theirs): array => ['publish'],
    ]],
    'a rule written in the active tenant' => [[
        'tenant' => 5,
        'engine' => fn (Warden $w, User $u, Account $a) => $w->allow($u)->to('publish'),
        'fake' => fn (WardenFake $f, User $u, Account $a) => $f->allow('publish')->inScope(5),
        'check' => fn (Account $mine, Account $theirs): array => ['publish'],
    ]],
    'a rule written in another tenant' => [[
        'tenant' => 5,
        'engine' => function (Warden $w, User $u, Account $a): void {
            $w->tenant()->to(6);
            $w->allow($u)->to('publish');
            $w->tenant()->to(5);
        },
        'fake' => fn (WardenFake $f, User $u, Account $a) => $f->allow('publish')->inScope(6),
        'check' => fn (Account $mine, Account $theirs): array => ['publish'],
    ]],
    'a global rule seen from inside a tenant' => [[
        'tenant' => 5,
        'engine' => function (Warden $w, User $u, Account $a): void {
            $w->tenant()->remove();
            $w->allow($u)->to('publish');
            $w->tenant()->to(5);
        },
        'fake' => fn (WardenFake $f, User $u, Account $a) => $f->allow('publish'),
        'check' => fn (Account $mine, Account $theirs): array => ['publish'],
    ]],
    'a string that names no model' => [[
        'engine' => fn (Warden $w, User $u, Account $a) => $w->allow($u)->to('edit', Account::class),
        'fake' => fn (WardenFake $f, User $u, Account $a) => $f->allow('edit', Account::class),
        'check' => fn (Account $mine, Account $theirs): array => ['edit', 'not-a-model'],
    ]],
]);

it('refuses to let a scripted forbid expire, exactly as the engine does', function (): void {
    $fake = $this->warden->fake();

    expect(fn (): mixed => $fake->forbid('publish')->until(Carbon::parse('2027-01-01 00:00:00')))
        ->toThrow(ConfigurationException::class, 'A forbid does not expire');
});
