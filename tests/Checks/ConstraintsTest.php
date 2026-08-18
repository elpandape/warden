<?php

declare(strict_types=1);

use ElPandaPe\Warden\Constraints\Builder;
use ElPandaPe\Warden\Constraints\ConstraintSerializer;
use ElPandaPe\Warden\Exceptions\ConfigurationException;
use ElPandaPe\Warden\Models\Permission;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Support\Facades\Gate;

use function ElPandaPe\Warden\Tests\Database\migrateWardenTables;

beforeEach(function (): void {
    migrateWardenTables();

    $this->warden = app(Warden::class);
    $this->user = User::query()->create(['name' => 'Joseph']);
});

it('grants conditionally on entity attribute values', function (): void {
    $mine = Account::query()->create(['name' => 'Mine'])->refresh();
    $other = Account::query()->create(['name' => 'Other'])->refresh();

    $this->warden->allow($this->user)->to('view', Account::class)->where('name', 'Mine');

    expect(Gate::forUser($this->user)->allows('view', $mine))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('view', $other))->toBeFalse();
});

it('supports explicit operators and authority column comparisons', function (): void {
    $mine = Account::query()->create(['name' => 'A', 'user_id' => $this->user->getKey()])->refresh();
    $foreign = Account::query()->create(['name' => 'B', 'user_id' => $this->user->getKey() + 10])->refresh();

    $this->warden->allow($this->user)->to('edit', Account::class)->whereColumn('user_id', 'id');
    $this->warden->allow($this->user)->to('rank', Account::class)->where('user_id', '>=', 1000);

    expect(Gate::forUser($this->user)->allows('edit', $mine))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('edit', $foreign))->toBeFalse()
        ->and(Gate::forUser($this->user)->allows('rank', $mine))->toBeFalse();
});

it('applies sql-style precedence: and binds tighter than or', function (): void {
    $published = Account::query()->create(['name' => 'Post', 'user_id' => 7])->refresh();
    $draft = Account::query()->create(['name' => 'Draft', 'user_id' => 8])->refresh();
    $ownDraft = Account::query()->create(['name' => 'Draft', 'user_id' => $this->user->getKey()])->refresh();

    // name = Post OR (name = Draft AND user_id = authority id)
    $this->warden->allow($this->user)->to('view', Account::class)
        ->where('name', 'Post')
        ->orWhere('name', 'Draft')->whereColumn('user_id', 'id');

    expect(Gate::forUser($this->user)->allows('view', $published))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('view', $ownDraft))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('view', $draft))->toBeFalse();
});

it('groups explicitly with closures', function (): void {
    $match = Account::query()->create(['name' => 'X', 'user_id' => 5])->refresh();
    $wrongName = Account::query()->create(['name' => 'Y', 'user_id' => 5])->refresh();

    $this->warden->allow($this->user)->to('view', Account::class)
        ->where(function (Builder $group): void {
            $group->where('name', 'X')->orWhere('name', 'Z');
        })
        ->where('user_id', 5);

    expect(Gate::forUser($this->user)->allows('view', $match))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('view', $wrongName))->toBeFalse();
});

it('never matches instance-less checks with a constrained row', function (): void {
    $this->warden->allow($this->user)->to('view', Account::class)->where('name', 'Mine');

    // Class-level and wildcard checks cannot verify the condition: fail closed.
    expect(Gate::forUser($this->user)->allows('view', Account::class))->toBeFalse();
});

it('keeps unconstrained twins apart from constrained ones', function (): void {
    $other = User::query()->create(['name' => 'Ana']);
    $account = Account::query()->create(['name' => 'Plain'])->refresh();

    $this->warden->allow($other)->to('view', Account::class);
    $this->warden->allow($this->user)->to('view', Account::class)->where('name', 'Mine');

    // Two catalog rows: refining one holder never mutates the shared row.
    expect(Permission::query()->where('name', 'view')->count())->toBe(2)
        ->and(Gate::forUser($other)->allows('view', $account))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('view', $account))->toBeFalse();
});

it('reuses the constrained twin for identical conditions', function (): void {
    $other = User::query()->create(['name' => 'Ana']);

    $this->warden->allow($this->user)->to('view', Account::class)->where('name', 'Mine');
    $this->warden->allow($other)->to('view', Account::class)->where('name', 'Mine');

    expect(Permission::query()->where('name', 'view')->count())->toBe(1);
});

it('cleans up the just-created base row after refining', function (): void {
    $this->warden->allow($this->user)->to('view', Account::class)->where('name', 'Mine');

    $options = Permission::query()->where('name', 'view')->sole()->getAttribute('options');

    expect($options)->not->toBeNull();
});

it('accumulates chained conditions on the same concession', function (): void {
    $both = Account::query()->create(['name' => 'Mine', 'user_id' => 5])->refresh();
    $oneOnly = Account::query()->create(['name' => 'Mine', 'user_id' => 6])->refresh();

    $this->warden->allow($this->user)->to('view', Account::class)
        ->where('name', 'Mine')
        ->where('user_id', 5);

    expect(Gate::forUser($this->user)->allows('view', $both))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('view', $oneOnly))->toBeFalse()
        ->and(Permission::query()->where('name', 'view')->count())->toBe(1);
});

it('constrains forbids too: forbidden wins only where conditions match', function (): void {
    $secret = Account::query()->create(['name' => 'Secret'])->refresh();
    $open = Account::query()->create(['name' => 'Open'])->refresh();

    $this->warden->allow($this->user)->to('view', Account::class);
    $this->warden->forbid($this->user)->to('view', Account::class)->where('name', 'Secret');

    expect(Gate::forUser($this->user)->allows('view', $open))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('view', $secret))->toBeFalse();
});

it('falls through to the next candidate when constraints fail', function (): void {
    $account = Account::query()->create(['name' => 'Any'])->refresh();

    // The specific-but-constrained row loses; the broad row still grants.
    $this->warden->allow($this->user)->to('view', $account)->where('name', 'Nope');
    $this->warden->allow($this->user)->to('view', Account::class);

    expect(Gate::forUser($this->user)->allows('view', $account))->toBeTrue();
});

it('fails closed on corrupted persisted constraints', function (): void {
    $account = Account::query()->create(['name' => 'Any'])->refresh();

    $this->warden->allow($this->user)->to('view', Account::class)->where('name', 'Any');
    Permission::query()->withoutGlobalScopes()->update(['options' => ['v' => 99, 'g' => 'junk']]);
    $this->warden->refresh();

    expect(Gate::forUser($this->user)->allows('view', $account))->toBeFalse();
});

it('compares strictly: no type juggling surprises', function (): void {
    $zero = Account::query()->create(['name' => '0'])->refresh();

    // Loose PHP would say '0' == false-ish matches; strict comparison won't.
    $this->warden->allow($this->user)->to('view', Account::class)->where('name', false);

    expect(Gate::forUser($this->user)->allows('view', $zero))->toBeFalse();
});

it('rejects constraints without a grant and unknown operators', function (): void {
    expect(fn () => $this->warden->allow($this->user)->where('name', 'x'))
        ->toThrow(ConfigurationException::class, 'call to() or toOwn() first')
        ->and(fn () => $this->warden->allow($this->user)->to('view')->where('name', 'like', 'x'))
        ->toThrow(ConfigurationException::class, 'Unsupported constraint operator [like].')
        ->and(fn () => $this->warden->allow($this->user)->to('tag')->where('name', 1.5, 'x'))
        ->toThrow(ConfigurationException::class, 'Unsupported constraint operator type.');
});

it('round-trips serialization strictly', function (): void {
    $group = new Builder()
        ->where('status', 'live')
        ->orWhere(fn (Builder $nested): Builder => $nested->where('tier', '>=', 2)->whereColumn('owner_id', 'id'))
        ->group();

    $serialized = ConstraintSerializer::serialize($group);
    $restored = ConstraintSerializer::deserialize($serialized);

    expect($restored?->toArray())->toBe($group->toArray())
        ->and(ConstraintSerializer::deserialize('{"broken'))->toBeNull()
        ->and(ConstraintSerializer::deserialize('[]'))->toBeNull()
        ->and(ConstraintSerializer::deserialize(['v' => 1, 'g' => ['t' => 'group', 'i' => [['not', ['t' => 'value', 'c' => 'x', 'o' => '=', 'v' => 1]]]]]))->toBeNull()
        ->and(ConstraintSerializer::deserialize(json_encode($serialized)))->not->toBeNull();
});

it('rejects structurally invalid persisted shapes', function (): void {
    $group = fn (mixed $items): array => ['v' => 1, 'g' => ['t' => 'group', 'i' => $items]];

    $validGroup = ['t' => 'group', 'i' => [['and', ['t' => 'value', 'c' => 'x', 'o' => '=', 'v' => 1]]]];

    // A wrong version must reject even a perfectly valid group shape.
    expect(ConstraintSerializer::deserialize(['v' => 99, 'g' => $validGroup]))->toBeNull()
        ->and(ConstraintSerializer::deserialize(['v' => 1, 'g' => 'junk']))->toBeNull()
        ->and(ConstraintSerializer::deserialize(['v' => 1, 'g' => ['t' => 'value', 'c' => 'x', 'o' => '=', 'v' => 1]]))->toBeNull();

    expect(ConstraintSerializer::deserialize($group([['and', 'junk']])))->toBeNull()
        ->and(ConstraintSerializer::deserialize($group([['and', ['t' => 'value', 'c' => 'x', 'o' => '=']]])))->toBeNull()
        ->and(ConstraintSerializer::deserialize($group([['and', ['t' => 'value', 'c' => 7, 'o' => '=', 'v' => 1]]])))->toBeNull()
        ->and(ConstraintSerializer::deserialize($group([['and', ['t' => 'column', 'c' => 'x', 'o' => '=', 'a' => 5]]])))->toBeNull()
        ->and(ConstraintSerializer::deserialize($group([['and', ['t' => 'column', 'c' => 'x', 'o' => '??', 'a' => 'y']]])))->toBeNull()
        ->and(ConstraintSerializer::deserialize(['v' => 1, 'g' => ['t' => 'group', 'i' => 'junk']]))->toBeNull()
        ->and(ConstraintSerializer::deserialize($group([['and']])))->toBeNull()
        ->and(ConstraintSerializer::deserialize($group([[5, ['t' => 'value', 'c' => 'x', 'o' => '=', 'v' => 1]]])))->toBeNull();
});

it('supports or-where operators and or-where-column variants', function (): void {
    $low = Account::query()->create(['name' => 'L', 'user_id' => 1])->refresh();
    $high = Account::query()->create(['name' => 'H', 'user_id' => 900])->refresh();
    $ownish = Account::query()->create(['name' => 'O', 'owner_id' => $this->user->getKey()])->refresh();

    $this->warden->allow($this->user)->to('view', Account::class)
        ->where('user_id', '>=', 500)
        ->orWhereColumn('owner_id', '=', 'id');

    $this->warden->allow($this->user)->to('tag', Account::class)
        ->where('name', 'none')
        ->orWhere('user_id', '<', 5);

    expect(Gate::forUser($this->user)->allows('view', $high))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('view', $ownish))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('view', $low))->toBeFalse()
        ->and(Gate::forUser($this->user)->allows('tag', $low))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('tag', $high))->toBeFalse();
});

it('never lets not-equal pass on unreadable attributes', function (): void {
    Account::query()->create(['name' => 'closed-one']);
    $projected = Account::query()->select(['id'])->where('name', 'closed-one')->sole();

    $this->warden->allow($this->user)->to('edit', Account::class)->where('name', '!=', 'closed-one');

    // The attribute is not hydrated: no operator may pass, not even !=.
    expect(Gate::forUser($this->user)->allows('edit', $projected))->toBeFalse();
});

it('keeps forbids in force when their constraints are undecidable', function (): void {
    $draft = Account::query()->create(['name' => 'Draft'])->refresh();

    $this->warden->allow($this->user)->to('delete', Account::class);
    $this->warden->forbid($this->user)->to('delete', Account::class)->where('name', 'Draft');

    expect(Gate::forUser($this->user)->allows('delete', $draft))->toBeFalse();

    // Corrupting the forbid's constraints must not lift the denial.
    Permission::query()->withoutGlobalScopes()
        ->whereNotNull('options')
        ->update(['options' => ['v' => 99, 'g' => 'junk']]);
    $this->warden->refresh();

    expect(Gate::forUser($this->user)->allows('delete', $draft))->toBeFalse()
        ->and(Gate::forUser($this->user)->allows('delete'))->toBeFalse();

    // The cache engine honors the same undecidable-forbid rule.
    config()->set('warden.cache.enabled', true);

    expect(Gate::forUser($this->user)->allows('delete', $draft))->toBeFalse();
});

it('never attaches a plain grant to a constrained twin', function (): void {
    $any = Account::query()->create(['name' => 'Any'])->refresh();

    // The constrained row exists first; its plain base was cleaned up.
    $this->warden->allow($this->user)->to('view', Account::class)->where('name', 'Nope');

    $other = User::query()->create(['name' => 'Ana']);
    $this->warden->allow($other)->to('view', Account::class);

    expect(Gate::forUser($other)->allows('view', $any))->toBeTrue()
        ->and(Permission::query()->where('name', 'view')->whereNull('options')->count())->toBe(1);
});

it('starts a fresh constraint set for each concession in a chain', function (): void {
    $this->warden->allow($this->user)->to('alpha')->where('name', 'X')->to('beta');

    // beta must not inherit alpha's constraints.
    expect(Gate::forUser($this->user)->allows('beta'))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('alpha'))->toBeFalse();
});

it('keeps type-distinct constraints on distinct twins', function (): void {
    $other = User::query()->create(['name' => 'Ana']);

    $this->warden->allow($this->user)->to('view', Account::class)->where('user_id', '1');
    $this->warden->allow($other)->to('view', Account::class)->where('user_id', 1);

    // '1' and 1 are different constraints: two rows, never a shared twin.
    expect(Permission::query()->where('name', 'view')->count())->toBe(2);
});
