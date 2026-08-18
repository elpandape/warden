# Bouncer

> Roles & permissions for Laravel — instance-level grants, explicit forbids, ownership,
> multi-tenancy, ABAC. PHP 8.4+ · Laravel 12+.
>
> Based on [Bouncer](https://github.com/JosephSilber/bouncer) by Joseph Silber — this package
> is a modernized evolution of his original work (MIT).

![Version](https://img.shields.io/badge/version-1.0.0--rc.1-blue) ![PHP](https://img.shields.io/badge/php-%5E8.4-777bb3) ![Laravel](https://img.shields.io/badge/laravel-12%20%7C%2013-ff2d20) ![Coverage](https://img.shields.io/badge/coverage-100%25-brightgreen) ![PHPStan](https://img.shields.io/badge/phpstan-max-4b5563)

> **Status: release candidate (v1.0.0-rc.1) — the API is frozen.**
> Only fixes land between here and 1.0.0. Mutation testing hardens the core suite.
> Do not use in production before v1.0.0.

## What's available (v1.0.0-rc.1)

- **Checks through Laravel's Gate**: `can()`, `@can`, `authorize()` and policies work
  out of the box — explicit forbids beat any grant, and Bouncer never overrides your
  policies unless you configure it to run first.
- **Cached checks** (default on): one minimal versioned payload per authority, O(1)
  automatic invalidation on every write, anti-stampede locking, Octane-safe.
- **Typed events** for every write — grants, forbids, roles, syncs with a full diff,
  catalog lifecycle — plus opt-in cancellable pre-action events.
- **Typed exceptions** (`UnauthorizedException`, `RoleDoesNotExist`, …) with
  translatable, leak-safe messages; `BackedEnum` accepted wherever a name string is.
- **ABAC constraints**: `allow()->to()->where('status', 'published')` — declarative
  conditions evaluated on every check, in both engines. No other Laravel package has it.
- **Model-scoped roles**: `assign('editor')->on($org)->to($user)` — the role's grants
  only apply inside that context; the same role can repeat across contexts.
- **`whereCan()`**: `Post::whereCan($user, 'view')->paginate()` — the only package
  whose data model can answer *over which rows*, constraints compiled to SQL included.
- **`explain()`**: why a check resolves the way it does — including "you are
  explicitly forbidden", which nobody else can say. Plus `Bouncer::fake()`,
  testing helpers and artisan commands.
- **A real migration path**: `bouncer:upgrade` transforms a silber/bouncer database
  in place, and a Rector set renames your code. See [UPGRADE.md](UPGRADE.md).
- Optional route middleware and a `@forbidden` Blade directive, off by default.
- **Ownership**: `toOwn(Post::class)` grants only what the user owns — resolved by
  attribute (configurable globally, per class, or with a closure), strict-mode safe.
- **Multi-tenancy**: `Bouncer::tenant()->to($id)` isolates the whole system per tenant,
  with global (unscoped) rows visible everywhere, an injectable tenant resolver,
  exception-safe `onceTo()`, and catalog/role-grant splits.
- **The fluent write API**: `Bouncer::allow($user)->to(...)`, `forbid()`, `disallow()`,
  `unforbid()`, `assign()`, `retract()`, `sync()`, `is()` — immediate execution, no
  destructor magic, safe under concurrency.
- Models: `Permission`, `Role`, and real pivot models (`Grant`, `AssignedRole`) you can
  swap or extend via config. Friendly titles are generated on creation (configurable).
- `HasRolesAndPermissions` for any authority model (not just users): `roles()`,
  `permissions()`, `isA()` / `isAn()` / `isNotA()` / `isAll()`.
- Stable morph aliases (`bouncer.role`, `bouncer.permission`), safe with
  `Relation::enforceMorphMap()`. UUID/ULID-ready: no hardcoded integer cast on entity
  ids, and the published migration ships commented column variants to switch to string keys.
- Schema v2 migration (frozen for 0.x), full config file, en/es translations, `Context`.

## Ownership

```php
Bouncer::allow($user)->toOwn(Post::class);            // any action on owned posts
Bouncer::allow($user)->toOwn(Post::class, ['edit']);  // only these actions
Bouncer::allow($user)->toOwnEverything();

Bouncer::ownedVia('author_id');                       // global attribute
Bouncer::ownedVia(Post::class, 'writer_id');          // per class
Bouncer::ownedVia(fn ($post, $user) => $post->team_id === $user->team_id);
```

✅ Do — let ownership carry the common case and forbid the exceptions:

```php
Bouncer::allow($user)->toOwn(Post::class);
Bouncer::forbid($user)->toOwn(Post::class, 'delete'); // owners still can't delete
```

❌ Don't — don't reimplement ownership inside policies you'll have to keep in
sync with your grants; `ownedVia()` is declared once and works everywhere.

## Multi-tenancy

```php
Bouncer::tenant()->to($tenantId);       // everything now scoped to this tenant
Bouncer::tenant()->onceTo(9, fn () => ...); // temporary, exception-safe
Bouncer::tenant()->onlyRelations();     // keep the permission catalog global
Bouncer::tenant()->dontScopeRoleGrants();
```

Rows written without an active tenant are global: visible from every tenant. What a
check sees with **no** active tenant is configurable (`bouncer.scope.null_behavior`:
`'all'` sees everything, `'strict'` sees only global rows) — the semantic ambiguity
that plagued the original is now an explicit choice. Wire a
`Contracts\TenantResolver` in config to detect the tenant from session/JWT automatically.

**Writes always target one exact scope.** Reads fall back to global rows, but a write
under tenant 5 creates, matches, or deletes tenant-5 rows only — a tenant-scoped
`disallow()`, `unforbid()`, `retract()` or `sync()` never destroys a global rule, and a
global write never gets absorbed by a same-named row inside some tenant.

✅ Do — remove a global forbid where it lives: outside any tenant:

```php
Bouncer::tenant()->removeOnce(fn () => Bouncer::unforbid($user)->to('publish'));
```

❌ Don't — don't expect `Bouncer::unforbid($user)->to('publish')` under a tenant to
lift a **global** forbid; global rules are only writable globally, by design.

## Conditional permissions (ABAC)

Grants can carry conditions, written in the grammar your queries already use and
evaluated on **every check**, cached or not:

```php
Bouncer::allow($user)->to('view', Document::class)
    ->where('status', 'published')
    ->orWhere(fn ($group) => $group->where('tier', '>=', 2)->whereColumn('owner_id', 'id'));
```

- `where('column', $value)` compares an attribute of the checked entity; explicit
  operators (`=`, `!=`, `<`, `<=`, `>`, `>=`) go in the middle, like Eloquent.
- `whereColumn('owner_id', 'id')` compares against the **authority**'s attribute.
- Precedence is SQL's: AND binds tighter than OR (`A or B and C` = `A or (B and C)`);
  nest a closure to group explicitly.
- Comparisons are strict — no PHP type juggling; numeric strings bridge to numbers.
- A constrained grant **never matches instance-less checks** (`can('view')`,
  `can('view', Document::class)`): conditions need an instance, so they fail closed.
- The persisted shape is versioned and enum-discriminated; corrupt data fails closed.

✅ Do — grant broadly, constrain the sensitive part; different holders never share rows:

```php
Bouncer::allow('viewer')->to('view', Document::class)->where('status', 'published');
Bouncer::forbid($user)->to('view', Document::class)->where('classified', true);
```

❌ Don't — don't encode workflow logic as constraints (drafts visible on Tuesdays);
constraints compare attributes. Complex rules belong in policies, which always win.

## Scoped roles

The columns other packages left dead for years, alive: restrict a role to any model —
no global `team_id` required, the context is whatever model you choose.

```php
Bouncer::assign('editor')->on($orgOne)->to($user);   // editor only inside orgOne
Bouncer::assign('editor')->on($orgTwo)->to($user);   // same role, second context
Bouncer::retract('editor')->on($orgOne)->from($user); // leave one; without on(), all

Bouncer::restrictedVia(Post::class, 'organization_id');           // membership by FK
Bouncer::restrictedVia(fn ($entity, $context) => ...);            // or a closure
```

A restricted role's grants apply when the checked entity **belongs to the context**:
it is the context itself, or its `{context}_id` attribute points at it (convention,
configurable per class or globally). Checks without an instance fail closed — a
restricted editor is not a global editor. `on()` goes **before** `to()`: writes are
immediate. Role membership checks (`isAn('editor')`) ignore restrictions by design.

✅ Do — model teams with the models you already have:

```php
Bouncer::assign('admin')->on($project)->to($user);
$user->can('manage', $project);          // true: the entity IS the context
$user->can('edit', $taskInProject);      // true: task->project_id points at it
```

❌ Don't — don't fall back to one global role plus scattered `if ($user->org_id === …)`
checks; the assignment carries its context, queryable and revocable per context.

## Querying by permission

Checks answer "can X do Y?"; the data model can also answer **"over which rows?"** —
as one composable, paginatable scope. Add the `QueriesByPermission` concern to the
models being authorized:

```php
use ElPandaPe\Bouncer\Concerns\QueriesByPermission;

Post::whereCan($user, 'view')->latest()->paginate();
```

Instance grants, class grants, wildcards, everyone-grants, role grants, forbids,
tenancy, ownership (attribute-resolved) and **ABAC constraints all compile into the
query**. What cannot become SQL fails closed, never open: closure-resolved ownership
and restricted-role **grants** contribute no rows, restricted-role **forbids**
over-block their whole shape, and constraint comparisons inside the query use the
database engine's semantics (a case-insensitive collation may match more than the
strict in-memory comparator; boolean values without a boolean cast match nothing,
exactly like `can()`).

✅ Do — drive index pages straight from authorization; no post-filtering, no N+1 checks.

❌ Don't — don't `->get()->filter(fn ($p) => $user->can('view', $p))`; that's the N+1
this scope exists to delete, and it breaks pagination counts.

## Debugging with explain()

```php
$why = Bouncer::explain($user, 'edit', $post);

$why->allowed();      // bool
$why->cause;          // Cause::ForbiddenViaRole, Cause::GrantedDirectly, …
$why->permission;     // the decisive catalog row
$why->role;           // the role that carried it, when one did
(string) $why;        // "Explicitly forbidden by permission [edit] via role [banned]."
```

Always answered by the database engine — never from cache — so it diagnoses stale-cache
gotchas too. Forbid precedence is absolute by contract; when it surprises someone,
`explain()` names the exact row and role to fix.

## Testing your app

```php
// Script verdicts without touching tables; unscripted checks fall to your policies.
$fake = Bouncer::fake();
$fake->allow('edit-site')->forbid('delete');

$fake->assertChecked('edit-site');
$fake->assertGranted('edit-site');
$fake->assertForbidden('delete');
$fake->assertNothingChecked();

// Or arrange real rows tersely with the trait:
use ElPandaPe\Bouncer\Testing\WithPermissions;

$this->allowUser($user, 'view', Document::class);
$this->assignRoles($user, 'admin');
```

Artisan ships too: `bouncer:install`, `bouncer:show [Class:id]`, `bouncer:cache-reset`,
`bouncer:clean --dry-run`, and a `php artisan about` section.

## Migrating from silber/bouncer

```bash
composer require elpandape/bouncer        # replaces silber/bouncer (conflict enforced)
php artisan bouncer:upgrade --dry-run     # report
php artisan bouncer:upgrade               # in-place schema transform
vendor/bin/rector process app --config vendor/elpandape/bouncer/stubs/rector-silber-upgrade.php
```

The fluent API is intentionally compatible, the schema upgrades in place (the
original's `permissions` pivot becomes `grants`, its `abilities` becomes
`permissions`, role morphs are rewritten), and the Rector set renames imports and
calls. Full table of equivalences and caveats: **[UPGRADE.md](UPGRADE.md)**.

## Optional middleware & Blade

Off by default — flip `bouncer.register_middleware_aliases` /
`bouncer.register_blade_directives`:

```php
Route::get('/admin', ...)->middleware('bouncer.role:admin,editor');      // any of
Route::put('/site', ...)->middleware('bouncer.permission:edit-site');    // all of
```

```blade
@forbidden('publish')  {{-- explicit denial — not the same as lacking it --}}
    You are explicitly banned from publishing.
@endforbidden
```

Both throw/render through the same typed, translatable machinery as everything else.

## Contracts & deferred decisions

- **Forbid precedence is absolute.** A matching `forbid()` beats every grant from any
  role, always — that is the model's security guarantee, not a missing feature. Forbid
  narrowly (specific roles, entities or constraints), never on broad global roles you
  then need exceptions to; `explain()` names the exact row and role when a denial
  surprises you. A forbid-with-exceptions mechanism is a candidate for post-1.0.
- **Multi-guard support** is formally deferred to the post-1.0 backlog: Bouncer
  authorizes *models*, and any authenticatable model already works. If your guards
  resolve different user models, each gets its own grants naturally.

## Caching

Enabled by default: the cached resolver stores one **minimal payload per authority**
(every grant tuple that could ever apply to it) and answers checks in memory with the
exact same semantics as the database engine — the whole test suite runs against both.
The first check per authority costs three queries; the next thousand cost zero.

Invalidation is automatic and O(1): every write through Bouncer bumps a version
counter for the exact tenant scope it touched, so stale entries are orphaned, never
hunted. Cold rebuilds take a lock when the store supports one (anti-stampede), and
payloads are versioned so future fields never break old entries.

```php
// config/bouncer.php — a real store with a TTL:
'cache' => [
    'enabled' => true,
    'store' => 'default',
    'prefix' => 'bouncer',
    'expiration_time' => DateInterval::createFromDateString('24 hours'),
],
```

```php
Bouncer::refresh();          // invalidate everything: an O(1) version bump
Bouncer::refreshFor($user);  // drop one authority's payload
```

✅ Do — write through Bouncer and let invalidation take care of itself:

```php
Bouncer::disallow($user)->to('publish');   // the next check is already correct
```

❌ Don't — the "I forgot to refresh the cache in prod" pattern: raw database edits
(seeders, manual SQL) bypass invalidation by design. After hand-editing rows, call
`Bouncer::refresh()` — or better, make the edit through the API.

One caveat: the in-memory matcher compares permission names **byte-exactly**, while a
case-insensitive database collation (MySQL's default) may match `Edit` to `edit`.
Use exact, consistent permission names — good practice with or without the cache.

## Checking permissions

Nothing to learn: it is Laravel's Gate.

```php
$user->can('edit-site');            // simple permission
$user->can('edit', $post);          // one instance
$user->can('edit', Post::class);    // the whole class
Gate::authorize('edit', $post);     // throws on deny
@can('edit', $post) ... @endcan     // Blade, as always
```

How grants match checks:

| Grant ↓ / Check → | `can('edit')` | `can('edit', Post::class)` | `can('edit', $post)` |
|---|---|---|---|
| `to('edit')` | ✅ | — | — |
| `to('edit', Post::class)` | — | ✅ | ✅ |
| `to('edit', $post)` | — | — | ✅ that one |
| `to('edit', '*')` | — | ✅ | ✅ |
| `to('*')` | ✅ | — | — |
| `toManage(Post::class)` | — | ✅ | ✅ |
| `everything()` | ✅ | ✅ | ✅ |

Rules worth knowing: an explicit `forbid()` beats every **Bouncer** grant; by default
Bouncer answers **after** your policies and Gate definitions, so those always win over
both grants and forbids (set `bouncer.gate.run_before_policies` to flip it, making
forbids veto everything). `Gate::after` fallbacks registered after Bouncer are consulted
only when Bouncer abstains. Checks with more than one argument are left entirely to your
policies; guests and non-model arguments are never answered. Denials carry a translatable
message (`bouncer::bouncer.unauthorized`, shipped in English and Spanish).

## Granting & forbidding

```php
use ElPandaPe\Bouncer\Facades\Bouncer;

Bouncer::allow($user)->to('ban-users');            // simple permission
Bouncer::allow($user)->to('edit', Post::class);    // every post
Bouncer::allow($user)->to('edit', $post);          // one post
Bouncer::allow($user)->everything();               // wildcard
Bouncer::allow($user)->toOwn(Post::class);         // only what they own
Bouncer::allowEveryone()->to('browse');            // everyone

Bouncer::assign('admin')->to($user);               // roles, created on the fly
Bouncer::allow('admin')->to('audit');              // grant to a role by name
Bouncer::sync($user)->roles(['editor', 'writer']); // declarative sync
```

✅ Do — forbid beats everything, use it for exceptions:

```php
Bouncer::allow($user)->to('view', Document::class);
Bouncer::forbid($user)->to('view', $classifiedDocument);
```

❌ Don't — don't model exceptions by scattering conditionals around your
codebase; an explicit `forbid()` row is queryable, auditable and revocable
(`Bouncer::unforbid($user)->to('view', $classifiedDocument)`).

## Events

Every write dispatches a typed, `readonly` event with **hydrated models — never raw
ids** (the payload asymmetry other packages document as a caveat doesn't exist here).
Disable globally with `bouncer.events_enabled`.

| Event | Fired by | Payload |
|---|---|---|
| `PermissionGranted` / `PermissionForbidden` | `allow()`, `forbid()` | `?Model $authority` (null = everyone), `Collection $permissions`, `$scope` |
| `PermissionRevoked` / `PermissionUnforbidden` | `disallow()`, `unforbid()` — only when rows were removed | same shape |
| `RoleAssigned` / `RoleRetracted` | `assign()`, `retract()` | `Model $authority`, `Collection $roles`, `$scope`, `?Model $restrictedTo` |
| `RolesSynced` / `PermissionsSynced` | `sync()` | `SyncResult` diff: `attached` / `detached` / `kept`, all hydrated |
| `RoleCreated/Deleted`, `PermissionCreated/Deleted` | the model layer — every creation path counts | the model |

```php
Event::listen(PermissionGranted::class, function (PermissionGranted $event) {
    audit('granted', $event->authority, $event->permissions->pluck('name'));
});
```

Pre-action events (`GrantingPermission`, `ForbiddingPermission`, `AssigningRole`) are
**opt-in** via `bouncer.cancellable_events`: a listener returning `false` aborts the
write before anything happens. `sync()` never fires nor honors them — its declarative
diff events tell the whole story.

✅ Do — audit from events; they fire on every write path, always after cache
invalidation, with symmetric payloads.

❌ Don't — don't hang business rules off post-action events; if a write must be
conditional, use the cancellable pre-action events (or just decide before calling).

## Exceptions

All typed, all catchable the Laravel way:

```php
Bouncer::findRole('ghost');            // RoleDoesNotExist (a ModelNotFoundException)
Bouncer::findPermission('ghost');      // PermissionDoesNotExist
Bouncer::authorize('publish', $post);  // UnauthorizedException (an AuthorizationException)
config(['bouncer.models.role' => Foo::class]); // ConfigurationException, fail-fast
```

`UnauthorizedException` exposes `getRequiredPermissions()` / `getRequiredRoles()`, and
its message is translatable (shipped in English and Spanish). Naming the missing
permission or role in the message is **opt-in** (`bouncer.exceptions.display_*`):
leaking your authorization model in 403 responses is a footgun, so the default stays
generic.

## Enums everywhere

Every public signature that takes a permission or role name also takes a
string-backed enum:

```php
enum Permission: string { case EditSite = 'edit-site'; }

Bouncer::allow($user)->to(Permission::EditSite);
Bouncer::assign(Role::Admin)->to($user);
$user->isAn(Role::Admin);
Bouncer::authorize(Permission::EditSite);
```

## Schema & models

Four tables: `permissions` (the catalog), `roles`, `assigned_roles` (role ↔ authority)
and `grants` (permission ↔ authority, with a `forbidden` flag — a grant row is exactly
one concession or one prohibition). Any model can hold roles and permissions:

```php
use ElPandaPe\Bouncer\Concerns\HasRolesAndPermissions;

class User extends Authenticatable
{
    use HasRolesAndPermissions;
}
```

Swap models through config — never by extending package internals:

```php
// ✅ Do — point the config at your model, keep the concern:
// config/bouncer.php
'models' => ['role' => App\Models\Role::class],

// app/Models/Role.php
class Role extends Model
{
    use ElPandaPe\Bouncer\Models\Concerns\IsRole;
}
```

```php
// ❌ Don't — don't hardcode package classes in relations or checks;
// resolve them via config so swapped models keep working everywhere.
$user->roles()->attach(ElPandaPe\Bouncer\Models\Role::first());
```

## Installation

```bash
composer require elpandape/bouncer
php artisan vendor:publish --tag=bouncer-config
php artisan vendor:publish --tag=bouncer-migrations
php artisan migrate
```

This package **conflicts with `silber/bouncer`** by design: same facade alias, same default
tables. Migrating from it will be a one-command upgrade (`bouncer:upgrade`, ships in v0.10.0).

## Configuration

Everything lives in `config/bouncer.php`: models, table names, database connection,
morph aliases, gate behavior, ownership, multi-tenancy scope semantics, cache, events
and i18n-safe exception messages. Every key is documented in the file itself.

## Development

No local PHP or Composer needed — everything runs through Docker:

```bash
make build      # build the dev image
make install    # composer install
make ci         # pint + phpstan + rector + tests (100% coverage) + type coverage
make test-dbs   # run the suite against MySQL 9 and Postgres 16
make mutation   # mutation testing over the core, one pass per path
make shell      # a shell inside the container
```

## Credits & license

Original concept and API design: [Joseph Silber](https://github.com/JosephSilber) —
this project started as an evolution of his Bouncer and keeps his copyright notice.
Licensed under the [MIT license](LICENSE.md).
