<?php

declare(strict_types=1);

use ElPandaPe\Warden\Checks\Explain\Cause;
use ElPandaPe\Warden\Constraints\Builder;
use ElPandaPe\Warden\Constraints\ConstraintSerializer;
use ElPandaPe\Warden\Constraints\Group;
use ElPandaPe\Warden\Constraints\ValueConstraint;
use ElPandaPe\Warden\Enums\ComparisonOperator;
use ElPandaPe\Warden\Enums\LogicalOperator;
use ElPandaPe\Warden\Events\GrantingPermission;
use ElPandaPe\Warden\Exceptions\ConfigurationException;
use ElPandaPe\Warden\Models\Permission;
use ElPandaPe\Warden\Support\PermissionIdentity;
use ElPandaPe\Warden\Tests\Fixtures\Account;
use ElPandaPe\Warden\Tests\Fixtures\BoolCastAccount;
use ElPandaPe\Warden\Tests\Fixtures\User;
use ElPandaPe\Warden\Warden;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
    $named = Account::query()->create(['name' => 'Acme'])->refresh();

    $this->warden->allow($this->user)->to('view', Account::class)->where('name', '>=', 5);

    expect(Gate::forUser($this->user)->allows('view', $named))->toBeFalse();
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
    $draft = Account::query()->create(['name' => 'Draft'])->refresh();

    $this->warden->allow($this->user)->to('alpha', Account::class)->where('name', 'X')->to('beta', Account::class);

    // beta must not inherit alpha's constraints.
    expect(Gate::forUser($this->user)->allows('beta', $draft))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('alpha', $draft))->toBeFalse();
});

it('keeps type-distinct constraints on distinct twins', function (): void {
    $other = User::query()->create(['name' => 'Ana']);

    $this->warden->allow($this->user)->to('view', Account::class)->where('user_id', '1');
    $this->warden->allow($other)->to('view', Account::class)->where('user_id', 1);

    // '1' and 1 are different constraints: two rows, never a shared twin.
    expect(Permission::query()->where('name', 'view')->count())->toBe(2);
});

it('shares one twin whichever operator leads the group', function (): void {
    $other = User::query()->create(['name' => 'Ana']);

    $this->warden->allow($this->user)->to('view', Account::class)->where('name', '=', 'Published');
    $this->warden->allow($other)->to('view', Account::class)->orWhere('name', '=', 'Published');

    expect(Permission::query()->where('name', 'view')->whereNotNull('options')->count())->toBe(1);
});

it('fails closed when the stored conditions cannot be decoded at all', function (): void {
    $account = Account::query()->create(['name' => 'Published'])->refresh();

    $this->warden->allow($this->user)->to('view', Account::class)->where('name', '=', 'Published');

    expect(Gate::forUser($this->user)->allows('view', $account))->toBeTrue();

    DB::table('permissions')->whereNotNull('options')->update(['options' => '{not json']);
    $this->warden->refresh();

    expect(Gate::forUser($this->user)->allows('view', $account))->toBeFalse();
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'sqlite',
    'Engines with a real json type reject the blob on write, so the row cannot exist there',
);

it('answers whether two option blobs name the same rule', function (): void {
    $plain = new Builder;
    $plain->where('name', '=', 'Published');

    $leadingOr = new Builder;
    $leadingOr->orWhere('name', '=', 'Published');

    $other = new Builder;
    $other->where('name', '=', 'Draft');

    expect(ConstraintSerializer::sameRule(
        ConstraintSerializer::serialize($plain->group()),
        ConstraintSerializer::serialize($leadingOr->group()),
    ))->toBeTrue()
        ->and(ConstraintSerializer::sameRule(
            ConstraintSerializer::serialize($plain->group()),
            ConstraintSerializer::serialize($other->group()),
        ))->toBeFalse();
});

it('refuses to constrain a permission that has no entity', function (): void {
    expect(fn (): mixed => $this->warden->allow($this->user)->to('export')->where('team_id', '=', 5))
        ->toThrow(ConfigurationException::class);
});

it('blocks rather than abstains when an entity-less constrained forbid cannot be evaluated', function (): void {
    $this->warden->forbid($this->user)->to('export');

    // The shape the fluent API now refuses, reached the only way left: straight
    // through the model, which is how an already-written row looks.
    $builder = new Builder;
    $builder->where('team_id', '=', 5);

    Permission::query()->where('name', 'export')->update([
        'options' => json_encode(ConstraintSerializer::serialize($builder->group())),
    ]);
    $this->warden->refresh();

    expect($this->warden->explain($this->user, 'export')->cause)->toBe(Cause::ForbiddenDirectly);
});

it('blocks in the cached engine too, where a wildcard would otherwise grant', function (): void {
    config()->set('warden.cache.enabled', true);

    $this->warden->allowEveryone()->everything();
    $this->warden->forbid($this->user)->to('export');

    $builder = new Builder;
    $builder->where('team_id', '=', 5);

    Permission::query()->where('name', 'export')->update([
        'options' => json_encode(ConstraintSerializer::serialize($builder->group())),
    ]);
    $this->warden->refresh();

    expect(Gate::forUser($this->user)->allows('export'))->toBeFalse();
});

it('does not aim a narrowing at the permission a vetoed call left behind', function (): void {
    config()->set('warden.cancellable_events', true);

    Event::listen(GrantingPermission::class, fn (GrantingPermission $event): bool => $event->permissions !== ['edit']);

    // The second to() is vetoed, so there is nothing left to refine: saying so
    // beats narrowing whichever concession the chain named before it.
    expect(fn (): mixed => $this->warden->allow($this->user)
        ->to('view', Account::class)
        ->to('edit', Account::class)
        ->where('name', '=', 'X'))->toThrow(ConfigurationException::class);

    expect(Permission::query()->where('name', 'view')->whereNull('options')->exists())->toBeTrue();
});

it('replaces a condition instead of stacking a second rule beside it', function (): void {
    $published = Account::query()->create(['name' => 'Published'])->refresh();
    $draft = Account::query()->create(['name' => 'Draft'])->refresh();

    $this->warden->allow($this->user)->to('view', Account::class)->where('name', '=', 'Published');
    $this->warden->allow($this->user)->to('view', Account::class)->where('name', '=', 'Draft');

    // The second chain edits the rule; it does not add a second one whose
    // union authorises both.
    expect(Gate::forUser($this->user)->allows('view', $draft))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('view', $published))->toBeFalse();
});

it('leaves the concession untouched when a chain fails midway', function (): void {
    $account = Account::query()->create(['name' => 'X'])->refresh();

    $this->warden->allow($this->user)->to('view', Account::class);

    expect(Gate::forUser($this->user)->allows('view', $account))->toBeTrue();

    // Fail between the delete and the row that replaces it, which is the window
    // the chain leaves open: without one, the concession is simply gone.
    Event::listen('eloquent.creating: '.ElPandaPe\Warden\Models\Grant::class, function (): void {
        throw new RuntimeException('interrupted');
    });

    try {
        $this->warden->allow($this->user)->to('view', Account::class)->where('name', '=', 'X');
    } catch (Throwable) {
        // the chain failed, which is the point
    }

    expect(Gate::forUser($this->user)->allows('view', $account))->toBeTrue();
});

it('ignores an empty nested group instead of turning a grant into a constrained one', function (): void {
    $account = Account::query()->create(['name' => 'X'])->refresh();

    $this->warden->allow($this->user)->to('view', Account::class)
        ->where(function (Builder $group): void {
            // deliberately empty
        });

    // An empty group adds no condition, so the grant must stay the plain one a
    // class-level check can still match.
    expect(Gate::forUser($this->user)->allows('view', Account::class))->toBeTrue();
});

it('refuses a group carrying the unimplemented negation instead of reading it as and', function (): void {
    $account = Account::query()->create(['name' => 'X'])->refresh();

    $this->warden->allow($this->user)->to('view', Account::class)->where('name', '=', 'X');

    // A stored NOT is undecidable: the serializer already refuses it, and the
    // evaluators must agree rather than quietly meaning AND.
    Permission::query()->whereNotNull('options')->update([
        'options' => json_encode(['v' => 1, 'g' => ['t' => 'group', 'i' => [['not', ['t' => 'value', 'c' => 'name', 'o' => '=', 'v' => 'X']]]]]),
    ]);
    $this->warden->refresh();

    expect(Gate::forUser($this->user)->allows('view', $account))->toBeFalse();
});

it('refuses to serialize the reserved not operator, at any depth', function (): void {
    $leaf = new Group([[LogicalOperator::Not, new ValueConstraint('name', ComparisonOperator::Equal, 'Acme')]]);

    expect(fn (): array => ConstraintSerializer::serialize($leaf))
        ->toThrow(ConfigurationException::class, 'reserved')
        ->and(fn (): array => ConstraintSerializer::serialize(new Group([[LogicalOperator::And, $leaf]])))
        ->toThrow(ConfigurationException::class, 'reserved');
});

it('refuses a condition that can never be true', function (): void {
    expect(fn (): mixed => $this->warden->forbid($this->user)->to('view', Account::class)->where('name', true))
        ->toThrow(ConfigurationException::class, 'can never be true');
});

it('accepts the condition when the column carries the matching cast', function (): void {
    $this->warden->forbid($this->user)->to('view', BoolCastAccount::class)->where('user_id', true);

    expect(Permission::query()->whereNotNull('options')->count())->toBe(1);
});

it('accepts a wildcard entity, which names no model to ask', function (): void {
    $this->warden->forbid($this->user)->everything()->where('name', true);

    expect(Permission::query()->whereNotNull('options')->count())->toBe(1);
});

it('refuses to evaluate a hand-built group carrying the reserved negation', function (): void {
    $account = Account::query()->create(['name' => 'Acme']);
    $group = new Group([[LogicalOperator::Not, new ValueConstraint('name', ComparisonOperator::Equal, 'Acme')]]);

    expect(fn (): bool => $group->passes($account, $this->user))
        ->toThrow(ConfigurationException::class, 'reserved');
});

it('gives an unreadable rule a fingerprint of its own, not its plain sister\'s', function (): void {
    $this->warden->allow($this->user)->to('view', Account::class);
    $this->warden->allow($this->user)->to('view', Account::class)->where('name', 'Acme');

    $plain = Permission::query()->whereNull('options')->sole();
    $twin = Permission::query()->whereNotNull('options')->sole();

    // Raw on purpose: through the model the cast re-encodes it as valid json,
    // which is the step that hides this shape.
    DB::table('permissions')->where('id', $twin->getKey())->update(['options' => 'null']);

    $reread = Permission::query()->whereKey($twin->getKey())->sole();
    $reread->setAttribute('title', 'Renamed');

    expect(fn (): bool => $reread->save())->not->toThrow(QueryException::class)
        ->and(PermissionIdentity::for($reread))->not->toBe(PermissionIdentity::for($plain))
        ->and(Permission::query()->count())->toBe(2);
});
