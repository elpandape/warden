<p align="center">
  <img src="https://repository-images.githubusercontent.com/1362165005/fe6eacd8-d46e-4109-ab9b-a19ead739ce2" alt="Warden" width="800">
</p>

<h1 align="center">Warden</h1>

<p align="center">
  <strong>Roles & permissions for Laravel</strong><br>
  Instance-level grants, explicit forbids, ownership, multi-tenancy, and ABAC.<br>
  <em>Authorization that explains itself.</em>
</p>

<p align="center">
  <a href="https://packagist.org/packages/elpandape/warden"><img src="https://img.shields.io/packagist/v/elpandape/warden?style=flat-square&color=blue" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/elpandape/warden"><img src="https://img.shields.io/packagist/dt/elpandape/warden?style=flat-square&color=green" alt="Total Downloads"></a>
  <a href="LICENSE.md"><img src="https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square" alt="License"></a>
  <a href="https://php.net"><img src="https://img.shields.io/badge/PHP-8.4+-777BB4?style=flat-square&logo=php" alt="PHP 8.4+"></a>
  <a href="https://laravel.com"><img src="https://img.shields.io/badge/Laravel-13+-FF2D20?style=flat-square&logo=laravel" alt="Laravel 13+"></a>
</p>

---

## 📖 Table of Contents

- [✨ Features](#-features)
- [📋 Requirements](#-requirements)
- [🚀 Installation](#-installation)
- [⚡ Quick Start](#-quick-start)
- [🔐 Checking Permissions](#-checking-permissions)
- [🎁 Granting & Forbidding](#-granting--forbidding)
- [⏳ Temporary Access](#-temporary-access)
- [🏠 Ownership](#-ownership)
- [🎯 Scoped Roles](#-scoped-roles)
- [🏢 Multi-tenancy](#-multi-tenancy)
- [🔧 Conditional Permissions (ABAC)](#-conditional-permissions-abac)
- [📊 Querying by Permission](#-querying-by-permission)
- [🔍 Debugging with `explain()`](#-debugging-with-explain)
- [📡 Events](#-events)
- [⚠️ Exceptions](#-exceptions)
- [🔢 Enums](#-enums)
- [💾 Caching](#-caching)
- [🧪 Testing](#-testing)
- [🛡️ Middleware & Blade](#-middleware--blade)
- [🏗️ Schema & Models](#️-schema--models)
- [⚙️ Configuration](#-configuration)
- [📖 Recipes](#-recipes)
- [🔄 Migrating from silber/bouncer](#-migrating-from-silberbouncer)
- [🧪 Development](#-development)
- [👤 Credits & License](#-credits--license)

---

## ✨ Features

| Feature | Description |
|---|---|
| 🎯 **Laravel's Gate, zero learning curve** | `can()`, `@can`, `authorize()` — works out of the box. |
| 🔒 **Explicit forbids** | A `forbid()` beats every grant. Distinguishes "denied" from "not granted." |
| 📊 **`whereCan()` query scope** | The only package that can answer *"over which rows?"* as an Eloquent scope. |
| 🔍 **`explain()` debugging** | Know *why* a check resolved the way it did — including "explicitly forbidden." |
| 🏗️ **ABAC constraints** | `where('status', 'published')` on grants — evaluated on every check. |
| 🏠 **Ownership** | `toOwn(Post::class)` — grant only what the user owns, resolved by attribute or closure. |
| 🎯 **Scoped roles** | `assign('editor')->on($org)` — same role, different contexts. |
| ⏳ **Temporary access** | `until($moment)` on a grant or an assignment — it stops authorizing on its own. |
| 🪆 **Nested roles** | A role inside a role lends its grants, off by default and switchable live. |
| 🏢 **Multi-tenancy** | Tenant-scoped rows with global fallback, injectable resolver, exception-safe `onceTo()`. |
| 💾 **Smart caching** | O(1) invalidation, versioned payloads, anti-stampede locking, Octane-safe. |
| 📡 **Typed events** | Every write dispatches a typed event with hydrated models — never raw IDs. |
| 🔢 **Enum support** | `BackedEnum` accepted everywhere a name string is. |
| 🧪 **Testing helpers** | `Warden::fake()`, `WithPermissions` trait, artisan commands. |
| 🔄 **Migration path** | `warden:upgrade` + Rector set for silber/bouncer users. |

---

## 📋 Requirements

| Requirement | Version |
|---|---|
| PHP | `^8.4` |
| Laravel | `^13.0` |

---

## 🚀 Installation

```bash
composer require elpandape/warden
php artisan warden:install --migrate
```

`warden:install` publishes the config, the migration, and runs it. You can also publish individually:

```bash
php artisan vendor:publish --tag=warden-config
php artisan vendor:publish --tag=warden-migrations
```

Then add the concern to your authority model(s):

```php
use ElPandaPe\Warden\Concerns\HasRolesAndPermissions;

class User extends Authenticatable
{
    use HasRolesAndPermissions;
}
```

> 🔄 **Coming from silber/bouncer?** This package conflicts with it by design (same default tables). Run `php artisan warden:upgrade` to migrate the schema in place. See [MIGRATING-FROM-BOUNCER.md](MIGRATING-FROM-BOUNCER.md).

> ⬆️ **Already on warden 1.x?** 2.0 adds a column and a unique index to `permissions`. Publish and run the upgrade migration — `vendor:publish --tag=warden-migrations-v2` then `migrate` — before the first write. See [UPGRADE.md](UPGRADE.md#from-1x-to-20).

---

## ⚡ Quick Start

```php
use ElPandaPe\Warden\Facades\Warden;

// Grant
Warden::allow($user)->to('edit', Post::class);

// Forbid (always wins)
Warden::forbid($user)->to('edit', $secretPost);

// Scoped role
Warden::assign('editor')->on($org)->to($user);

// Check
$user->can('edit', $post);                          // Laravel's Gate
Post::whereCan($user, 'edit')->paginate();           // Which rows?
Warden::explain($user, 'edit', $post);               // Why?
```

---

## 🔐 Checking Permissions

Nothing to learn — it's Laravel's Gate.

```php
$user->can('edit-site');            // simple permission
$user->can('edit', $post);          // one instance
$user->can('edit', Post::class);    // the whole class
Gate::authorize('edit', $post);     // throws on deny
@can('edit', $post) ... @endcan     // Blade, as always
```

### Grant vs Check Matrix

| Grant ↓ / Check → | `can('edit')` | `can('edit', Post::class)` | `can('edit', $post)` |
|---|---|---|---|
| `to('edit')` | ✅ | — | — |
| `to('edit', Post::class)` | — | ✅ | ✅ |
| `to('edit', $post)` | — | — | ✅ that one |
| `to('edit', '*')` | — | ✅ | ✅ |
| `to('*')` | ✅ | — | — |
| `toManage(Post::class)` | — | ✅ | ✅ |
| `everything()` | ✅ | ✅ | ✅ |

> 📌 **Rules:**
> - `forbid()` beats every Warden grant.
> - By default, Warden answers **after** your policies — policies always win.
> - Set `warden.gate.run_before_policies` to make forbids veto everything.
> - Checks with more than one argument are left to your policies.
> - Guests and non-model arguments are never answered by Warden.
> - `Warden::can()` inside a policy **recurses through the Gate**. Ask the resolver directly — `app(Contracts\Resolver::class)` — when a policy needs Warden's own answer.
> - With `warden.gate.register` off, Warden abstains from every Gate answer, so a loose permission with no policy behind it reads as denied by **every** route through the Gate — `$user->can()`, `Warden::can()`, `cannot()`, `canAny()`, `authorize()` and the `warden.permission` middleware all go through the same Gate. What keeps answering is the resolver, `app(Contracts\Resolver::class)` — and your policies, wherever you have one.

---

## 🎁 Granting & Forbidding

```php
use ElPandaPe\Warden\Facades\Warden;

// Simple permission
Warden::allow($user)->to('ban-users');

// Class-level
Warden::allow($user)->to('edit', Post::class);

// Instance-level
Warden::allow($user)->to('edit', $post);

// Wildcard
Warden::allow($user)->everything();

// Everyone
Warden::allowEveryone()->to('browse');

// Roles
Warden::assign('admin')->to($user);
Warden::allow('admin')->to('audit');

// Declarative sync
Warden::sync($user)->roles(['editor', 'writer']);
```

> 📌 **An authority is a saved model with a usable key.** `allow()`, `forbid()`, `assign()->to()` and `sync()` refuse one that is not saved — a deleted one included — or whose key is not an int or a non-empty string, with a `ConfigurationException` thrown before any row is written: the row would name nobody while the event named the model. `disallow()`, `unforbid()` and `retract()->from()` only need the key, so they keep working from a model's own `deleted` hook. When everyone is what you mean, say so with `allowEveryone()`.

> 📌 **Only `allowEveryone()` grants to everyone.** Its rows carry neither an authority type nor a key, and those are the only rows `can()`, `whereCan()` and `getPermissions()` read as everyone's. A row with a type and no key — 3.0.0 wrote one for an unsaved authority — grants nobody.

> 📌 **Assignments are one hop unless you turn nesting on.** `assign('auditor')->to($role)` writes an edge between roles. By default holders of the outer role gain nothing from it — set `warden.roles.nested` to `true` and they inherit the inner role's grants, to `warden.roles.max_depth` levels deep.
>
> ```php
> // config/warden.php
> 'roles' => ['nested' => true, 'max_depth' => 10],
> ```
>
> **Off by default on purpose**, because turning it on widens what every existing assignment reaches. The switch is read on every check rather than baked into a cached payload, so turning it back off takes effect immediately — it is meant to work as an emergency lever. A cycle stops expanding at the depth ceiling instead of throwing.
>
> `can()` and every role check nest together — `isA()` and its variants, `isAll()`, `Warden::is()`, `whereIs()`, `whereIsAll()`, `whereIsNot()` and the `warden.role` middleware: a split would let `can('publish')` say yes while `Warden::is($user)->an('editor')` says no, painting a menu wrong for precisely the users with the most access.
>
> `$role->nestedRoles()` only reads the edges; `Warden::assign($inner)->to($outer)` and `Warden::retract($inner)->from($outer)` write them, scoped to the tenant, with the cache invalidated and the event fired. Every writer the relation declares — `attach()`, `detach()`, `sync()`, `toggle()`, `updateExistingPivot()`, `save()`, `create()`, `firstOrCreate()` and the rest, `OrFail` and `Quietly` variants included — throws a `ConfigurationException` pointing to those two, because a write through the relation would reach every tenant and context at once, with no invalidation and no event.
>
> `$role->nestedRoles()->delete()` deletes the inner roles themselves, as on any Eloquent relation — it is not an unnest. It runs through the query builder, so no model event fires and the cache is not invalidated, and the foreign key takes every assignment of those roles with them.

### Best Practices

✅ **Do** — use `forbid()` for exceptions:

```php
Warden::allow($user)->to('view', Document::class);
Warden::forbid($user)->to('view', $classifiedDocument);
```

❌ **Don't** — model exceptions with scattered conditionals; a `forbid()` row is queryable, auditable, and revocable:

```php
Warden::unforbid($user)->to('view', $classifiedDocument);
```

---

## ⏳ Temporary Access

Grants and role assignments can carry an end date. Past it they stop authorizing, stop appearing in `whereCan()`, and stop being listed by `getPermissions()` — no command has to run for that to happen.

```php
use ElPandaPe\Warden\Facades\Warden;

Warden::allow($user)->until(now()->addDays(7))->to('publish', Post::class);
Warden::assign('auditor')->until($audit->ends_at)->to($user);

// Lift an end date a previous write left; saying nothing leaves it alone.
Warden::allow($user)->until(null)->to('publish', Post::class);
```

> 📌 **The date lives on the assignment, not on the role.** A role is a shared definition, so an end date there would end it for everyone. On the assignment, the same role can end on different days for different holders — and when it does, the permissions that role lent go with it.

> 📌 **An expired assignment stops counting as held**, not only for `can()`. `isA()`, `isAll()`, `Warden::is()`, `whereIs()`, `whereIsAll()` and `whereIsNot()` read the date too, with nesting on or off, and so does the `warden.role` middleware, which answers an expired role with a 403. The relation is left alone: `$user->roles` still lists the row until something deletes it, with its date on the pivot's `expires_at`.

> 📌 **`until()` goes before `to()`**, like `on()`: writes execute immediately, so calling it afterwards throws rather than quietly doing nothing. Moving a date counts as a write — it invalidates the cache and fires the same event as any other.

> 📌 **A condition keeps the date.** `where()` re-points a grant at its constrained twin (see [Conditional Permissions](#-conditional-permissions-abac)), and the twin takes the date the same chain declared — `until(null)` included, which lifts it. Without `until()` it keeps the date the grant already had; if the authority held that permission both plain and under a condition, with different dates, the later one wins and no end date beats any.

> 📌 **Re-assigning does not revive an expired row.** Saying nothing about time leaves the date alone, and that date has passed: `assign('auditor')->to($user)` or `allow($user)->to('publish')` over an expired row writes nothing, and the access stays ended. Give it a new date, or `until(null)` to make it permanent.

> ⚠️ **A `forbid()` cannot expire**, and `until()` on one throws. A prohibition that lapsed by clock would turn *a forbid beats every grant* into *until Tuesday*, with the grant beneath it still live. Lift it deliberately with `unforbid()`.

> 📌 **A grant reached through a role outlives neither**: the earlier of the two dates ends it.

> 📌 **The boundary is exclusive.** A row stops counting at the instant it names, not a tick later.

`php artisan warden:clean --expired` deletes rows past their date. It is hygiene, not part of the mechanism: **an expired grant stops authorizing whether or not anyone runs it.**

---

## 🏠 Ownership

```php
// All actions on owned posts
Warden::allow($user)->toOwn(Post::class);

// Only specific actions
Warden::allow($user)->toOwn(Post::class, ['edit']);

// Everything owned
Warden::allow($user)->toOwnEverything();
```

### Configure ownership resolution

```php
// Global attribute
Warden::ownedVia('author_id');

// Per class
Warden::ownedVia(Post::class, 'writer_id');

// Closure (evaluated live, never cached)
Warden::ownedVia(fn ($post, $user) => $post->team_id === $user->team_id);

// This class has no owner at all — overrides the global fallback
Warden::notOwned(Setting::class);
```

> 📌 **`ownedVia()` only registers; it never removes.** `ownedVia(Post::class, null)` sets the *global* attribute to `"App\Models\Post"`, which is never what you meant. Use `notOwned()` to take one class out, or `'default_attribute' => null` in the config to turn the fallback off everywhere.

> 📌 **A `toOwn()` grant against a class that resolves no ownership can never grant.** The row is written and looks healthy; warden logs a warning so it is greppable.

### Best Practices

✅ **Do** — let ownership carry the common case, forbid the exceptions:

```php
Warden::allow($user)->toOwn(Post::class);
Warden::forbid($user)->toOwn(Post::class, 'delete'); // owners still can't delete
```

❌ **Don't** — reimplement ownership inside policies you'll have to keep in sync.

---

## 🎯 Scoped Roles

Restrict a role to any model — no global `team_id` required.

```php
Warden::assign('editor')->on($orgOne)->to($user);   // editor only inside orgOne
Warden::assign('editor')->on($orgTwo)->to($user);   // same role, second context
Warden::retract('editor')->on($orgOne)->from($user); // leave one; without on(), all
```

### Configure membership resolution

```php
Warden::restrictedVia(Post::class, 'organization_id');  // membership by FK
Warden::restrictedVia(fn ($entity, $context) => ...); // or a closure
```

> 📌 A restricted role's grants apply when the checked entity **belongs to the context**. Checks without an instance fail closed. Role membership checks (`isAn('editor')`) ignore restrictions by design — but not end dates: an assignment past its `until()` stops counting, restricted or not.

### Best Practices

✅ **Do** — model teams with the models you already have:

```php
Warden::assign('admin')->on($project)->to($user);
$user->can('manage', $project);          // true: the entity IS the context
$user->can('edit', $taskInProject);      // true: task->project_id points at it
```

❌ **Don't** — fall back to one global role plus scattered `if ($user->org_id === …)` checks.

---

## 🏢 Multi-tenancy

```php
Warden::tenant()->to($tenantId);                    // scope everything to this tenant
Warden::tenant()->onceTo(9, fn () => ...);         // temporary, exception-safe
Warden::tenant()->onlyRelations();                  // keep permission catalog global
Warden::tenant()->dontScopeRoleGrants();
```

### Behavior with no active tenant

Configure `warden.scope.null_behavior`:
- `'all'` — sees everything (global + all tenants)
- `'strict'` — sees only global rows

> 📌 **Writes always target one exact scope.** A write under tenant 5 only affects tenant-5 rows. Global rules are only writable globally.

Reads and deletes are therefore asymmetric: a check under tenant 5 answers *global **or** tenant 5*, while `retract()` and `disallow()` delete tenant-5 rows only. So a retract under a tenant can succeed and leave the authority still holding the role globally. `retract()->from()` exposes `retractedCount()` for callers that need to tell the cases apart:

```php
$removed = Warden::retract('editor')->from($user)->retractedCount();  // rows deleted at this scope
```


> ⚠️ **The scope rule is warden's, not Eloquent's.** `TenantScope` filters reads and stamps creates; it does not isolate writes. `$permission->delete()` and `$role->delete()` reach rows in every tenant, and the foreign keys cascade below Eloquent entirely. Remove rows through warden's own verbs, or through `warden:clean`.

> ⚠️ **Pivot tenancy is a plain predicate, not a registered scope**, so `withoutGlobalScopes()` does not lift it. Widen deliberately with `Warden::tenant()->removeOnce(...)`, which is the supported escape hatch.
>
> **Under `null_behavior => 'strict'` it narrows instead.** With no active tenant, strict reads only global rows, so `removeOnce()` turns `(scope is null or scope = $tenant)` into `scope is null` — strictly fewer rows than the read it was meant to widen. Under the default `'all'` it widens as described.

> 📌 **Relation writes obey the rule too.** `detach()`, `sync()`, `toggle()`, `syncWithoutDetaching()` and `updateExistingPivot()` on `roles()` and `permissions()` touch only rows at the active write scope, and `attach()` stamps it. A global row the tenant inherits stays out of reach in both directions: under tenant 5, `sync([$role])` adds the tenant-5 row beside the global one instead of adopting it, and `sync([])` leaves the global one standing.

> ⚠️ **Scope, yes; restriction, no.** A relation write narrows to one scope and stops there: it does not filter `restricted_to_*`, so `$user->roles()->detach($editor)` removes the scoped-role assignments along with the plain one. That mirrors `Warden::retract('editor')->from($user)` without `->on()`, which deletes them all the same way — the relation is not narrower than the verb it reflects. To remove one context and leave the others, name it: `Warden::retract('editor')->on($org)->from($user)`.

> ⚠️ **A relation captures its write scope when it is built, not when it writes.** `$user->roles()` resolves the active tenant at construction time, so a relation held in a property across a tenant change still writes to the scope it was born in. Ask for it again after switching tenants, or write through `Warden::assign()` / `retract()`, which resolve the scope per call — and which also dispatch the typed events and report `retractedCount()`.

> 📌 **A role is global unless the write mints a tenant one.** Under an active tenant, `allow('editor')` attaches to a global `editor` if one exists, rather than creating a tenant-scoped twin. Roles are looked up by name and scope; a tenant twin only exists once something writes it.

> 📌 **The permission catalog behaves the same way**, and in both halves a row the tenant minted for itself wins the global one it shadows. Under a tenant, `allow($user)->to('publish')` reuses a global `publish` row when that is all there is, and picks the tenant's own the moment one exists. Set `Warden::tenant()->onlyRelations()` to keep the catalog global on purpose.

> 📌 `Tenancy::writeScope()` takes `forRoleGrant`, and it defaults to `false`. A bare call therefore reports the scope of an authority grant; ask with `forRoleGrant: true` when the holder is a role, or the answer describes a different write than the one you meant.

### Best Practices

✅ **Do** — remove a global forbid where it lives: outside any tenant:

```php
Warden::tenant()->removeOnce(fn () => Warden::unforbid($user)->to('publish'));
```

❌ **Don't** — expect a tenant-scoped `unforbid()` to lift a **global** forbid.

---

## 🔧 Conditional Permissions (ABAC)

Grants can carry conditions, written in the grammar your queries already use:

```php
Warden::allow($user)->to('view', Document::class)
    ->where('status', 'published')
    ->orWhere(fn ($group) => $group
        ->where('tier', '>=', 2)
        ->whereColumn('owner_id', 'id')
    );
```

### Available operators

| Method | Description |
|---|---|
| `where('col', 'value')` | Entity attribute equals value |
| `where('col', '>=', 5)` | With explicit operator |
| `whereColumn('owner_id', 'id')` | Compare against authority's attribute |
| `orWhere(...)` | OR grouping |
| `orWhere(fn)` | Nested closure grouping |

> 📌 **Important:**
> - Precedence is SQL's: `AND` binds tighter than `OR`.
> - Comparisons are strict — no PHP type juggling.
> - A **null** attribute satisfies no operator at all, `!=` included, and values whose types are not decidably comparable fail closed the same way. A **missing** attribute is different: under `Model::preventAccessingMissingAttributes()` it throws rather than failing closed.
> - Constrained grants share one catalog row per distinct rule, so **editing a permission's options changes the rule for every holder of that shape**. Write a new condition instead of editing a shared row.
> - A **boolean** value matches only a column the model casts to `bool`, and such a column matches only a boolean, so **writing either mismatch is refused**: `where('classified', true)` needs `'classified' => 'bool'` in the model's `$casts`. A row stored before 3.0 keeps failing closed in checks and in queries alike — `php artisan warden:doctor` lists those rows.
> - The refusal exists because of what the mismatch does to a `forbid()`: a condition that can never be true makes the prohibition inert, the grant underneath it stays live, and `explain()` reports that grant without ever mentioning the forbid — a missing cast read as "allowed".
> - A permission with **no entity** is only ever checked without an instance, so constraining one is refused: the shape that would make it match is the shape that rejects it.
> - A constrained grant **never matches instance-less checks** (`can('view')`, `can('view', Document::class)`) — they fail closed.
> - **`to()->where()` is two writes, not one.** `to()` lands an unconstrained grant that authorises every instance, and `where()` re-points it at the constrained twin. Only the second step runs in a transaction; between the two the live row is unconditional, and a throw in `where()` — an unknown operator, a permission with no entity — leaves it that way. Wrap the whole chain in your own transaction when that window matters.

### Best Practices

✅ **Do** — grant broadly, constrain the sensitive part:

```php
Warden::allow('viewer')->to('view', Document::class)->where('status', 'published');
Warden::forbid($user)->to('view', Document::class)->where('classified', true);
```

❌ **Don't** — encode workflow logic as constraints (e.g., "drafts visible on Tuesdays"). Complex rules belong in policies.

---

## 📊 Querying by Permission

Checks answer "can X do Y?"; Warden can also answer **"over which rows?"**

```php
use ElPandaPe\Warden\Concerns\QueriesByPermission;

class Post extends Model
{
    use QueriesByPermission;
}

// Usage
Post::whereCan($user, 'view')->latest()->paginate();
```

Instance grants, class grants, wildcards, everyone-grants, role grants, forbids, tenancy, ownership, and **ABAC constraints all compile into the query**.

> ⚠️ What cannot become SQL fails closed: closure-resolved ownership and restricted-role grants contribute no rows.

> ⚠️ **No Gate, no policies.** `whereCan()` answers from warden's own rows only. A policy that would have granted or denied a row is not consulted, so a query and a check can disagree wherever a policy has the last word.

> ⚠️ **The trait is required.** Without it, `Post::whereCan($user, 'view')` never reaches Warden: Laravel reads it as a dynamic `where` against a column named `can`, and you get zero rows or a driver error instead of an answer.

### Best Practices

✅ **Do** — drive index pages straight from authorization:

```php
Post::whereCan($user, 'view')->latest()->paginate();
```

❌ **Don't** — post-filter with `->get()->filter(fn ($p) => $user->can('view', $p))` — that's the N+1 this scope exists to delete.

---

## 🔍 Debugging with `explain()`

```php
$why = Warden::explain($user, 'edit', $post);

$why->allowed();      // bool
$why->cause;          // Cause::ForbiddenViaRole, Cause::GrantedDirectly, …
$why->permission;     // the decisive catalog row, when one decided
$why->role;           // the role that carried it, when one did
(string) $why;        // "Explicitly forbidden by permission [edit] via role [banned]."
```

Which of `permission` and `role` are populated depends on the cause:

| Cause | `allowed()` | `permission` | `role` |
|---|---|---|---|
| `GrantedDirectly` | `true` | the row | — |
| `GrantedViaRole` | `true` | the row | the role |
| `GrantedToEveryone` | `true` | the row | — |
| `ForbiddenDirectly` | `false` | the row | — |
| `ForbiddenViaRole` | `false` | the row | the role |
| `ForbiddenToEveryone` | `false` | the row | — |
| `ConditionsNotMet` | `false` | the row whose conditions were not satisfied | — |
| `NoMatchingGrant` | `false` | — | — |
| `NotApplicable` | `false` | — | — |

> 📌 `ConditionsNotMet` and `NoMatchingGrant` are different answers: the first names a row that matched the shape but whose conditions were not satisfied — they failed against the instance, or the check named a class and there was no instance to satisfy them with — while the second means nothing matched at all. Both leave Warden abstaining so your policies decide.

> 📌 Always answered by the database engine — never from cache — so it diagnoses stale-cache issues too.

---

## 📡 Events

Every write dispatches a typed, `readonly` event with **hydrated models** (never raw IDs). Disable globally with `warden.events_enabled`.

| Event | Fired By | Payload |
|---|---|---|
| `PermissionGranted` / `PermissionForbidden` | `allow()`, `forbid()` | `?Model $authority`, `Collection $permissions`, `$scope`, `?Model $actor` |
| `PermissionRevoked` / `PermissionUnforbidden` | `disallow()`, `unforbid()` | Same shape |
| `RoleAssigned` / `RoleRetracted` | `assign()`, `retract()` | `Model $authority`, `Collection $roles`, `$scope`, `?Model $restrictedTo`, `?Model $actor` |
| `RolesSynced` / `PermissionsSynced` | `sync()` | `SyncResult` diff: `attached` / `detached` / `kept` |
| `RoleCreated/Deleted`, `PermissionCreated/Deleted` | Model layer | The model |

```php
use ElPandaPe\Warden\Events\PermissionGranted;

Event::listen(PermissionGranted::class, function (PermissionGranted $event) {
    // $authority receives the permission; $actor is who granted it.
    audit('granted', $event->actor, $event->authority, $event->permissions->pluck('name'));
});
```

`$actor` defaults to the authenticated user. Queues, console commands and impersonation are cases only your application can answer, so point `warden.actor_resolver` at a class implementing `Contracts\ActorResolver`:

```php
final class CurrentActor implements ActorResolver
{
    public function resolve(): ?Model
    {
        return Context::actingUser() ?? Auth::user();
    }
}
```

### Pre-action events (opt-in)

Enable with `warden.cancellable_events`. A listener returning `false` aborts the write:

```php
// GrantingPermission, ForbiddingPermission, AssigningRole
// RevokingPermission, UnforbiddingPermission, RetractingRole
```

> 📌 A pre-action event covers the **whole call**, not one item of it. `allow($user)->to(['a', 'b'])` announces both names in one event, and a listener returning `false` aborts **both**: there is no way to veto one and keep the other. Split the call if you need per-item decisions.

> 📌 `sync()` never fires nor honors pre-action events — its declarative diff events tell the whole story.

> 📌 **Deleting a catalog row settles before anyone hears of it.** The cache is invalidated and a role's own grants are swept first; then `RoleDeleted` or `PermissionDeleted` goes out; then, for a permission, the cascade below. A listener that throws can no longer leave checks answering for a row that is gone — it only stops the announcements after it. The flip side: a `RoleDeleted` listener no longer finds the role's grants. Read them in a `deleting` listener on the role model if you need them.

> 📌 **Deleting a catalog row announces what the cascade was predicted to reach.** A foreign key removes a permission's grants inside the engine, where no model event fires, so warden reads the doomed rows *before* the delete and dispatches one `PermissionRevoked` — or `PermissionUnforbidden` — per row afterwards. The read is the announcement: if the foreign key is not enforced, the events describe a deletion that did not happen.

> 📌 **That cascade is blind to the active tenant.** The doomed rows are read with `withoutGlobalScopes()`, on purpose — the delete destroys every tenant's grants regardless of which one is active, so counting only the current tenant would promise a smaller loss than the real one. Each holder is loaded the same way, so one under another tenant arrives named rather than as a `null` authority, which would read as *everyone*. A holder whose row is already gone is not announced — it authorized nobody — and neither is a row with a type and no key; one whose morph alias maps to no class in this process is skipped with a warning in the log.

---

## ⚠️ Exceptions

All typed, all catchable the Laravel way:

```php
Warden::findRole('ghost');            // RoleDoesNotExist (ModelNotFoundException)
Warden::findPermission('ghost');      // PermissionDoesNotExist
Warden::authorize('publish', $post);  // UnauthorizedException (AuthorizationException)
```

| Exception | Extends | Notes |
|---|---|---|
| `RoleDoesNotExist` | `ModelNotFoundException` | — |
| `PermissionDoesNotExist` | `ModelNotFoundException` | — |
| `UnauthorizedException` | `AuthorizationException` | `getRequiredPermissions()` / `getRequiredRoles()` |
| `ConfigurationException` | — | Fail-fast on bad config |

> 📌 `UnauthorizedException` messages are translatable (shipped in English and Spanish). Displaying the missing permission/role name in the message is **opt-in** via `warden.exceptions.display_*`.

---

## 🔢 Enums

Every public signature that takes a permission or role name also accepts a string-backed enum:

```php
enum Permission: string
{
    case EditSite = 'edit-site';
}

enum Role: string
{
    case Admin = 'admin';
}

Warden::allow($user)->to(Permission::EditSite);
Warden::assign(Role::Admin)->to($user);
$user->isAn(Role::Admin);
Warden::authorize(Permission::EditSite);
```

---

## 💾 Caching

Enabled by default. One minimal payload per authority, O(1) automatic invalidation, anti-stampede locking, Octane-safe.

```php
// config/warden.php
'cache' => [
    'enabled' => true,
    'store' => 'default',
    'prefix' => 'warden',
    'expiration_time' => DateInterval::createFromDateString('24 hours'),
],
```

### Manual invalidation

```php
Warden::refresh();          // O(1) version bump — invalidates everything
Warden::refreshFor($user);  // Drop one authority's payload
```

### Best Practices

✅ **Do** — write through Warden and let invalidation take care of itself:

```php
Warden::disallow($user)->to('publish');   // next check is already correct
```

❌ **Don't** — raw database edits (seeders, manual SQL) bypass invalidation. After hand-editing rows, call `Warden::refresh()` — or better, make the edit through the API.

> 📌 **"Through the API" includes the models.** Editing a `Grant`, an `AssignedRole` or a **catalog row** through Eloquent invalidates too — renaming a permission or rewriting its `options` reaches every cached check, because a permission's own columns are baked into the payload. Moving a row's `scope` that way invalidates the tenant it left as well as the one it joined. What still needs `Warden::refresh()` is a write that fires no model event: the query builder, `DB::table()`, and a raw statement.

> ⚠️ The in-memory matcher compares permission names **byte-exactly**, while a case-insensitive database collation may match `Edit` to `edit`. Use exact, consistent names.

---

## 🧪 Testing

### Fake mode

```php
$fake = Warden::fake();
$fake->allow('edit-site')->forbid('delete');

$fake->assertChecked('edit-site');
$fake->assertGranted('edit-site');
$fake->assertForbidden('delete');
$fake->assertNothingChecked();
```

A scripted rule answers for every authority unless you narrow it. Each verb below narrows the rule scripted just before it, so they chain:

```php
$fake->allow('publish')->for($editor);                  // this authority only
$fake->allow('edit', Post::class)->owned();             // only what they own
$fake->allow('edit', Post::class)->where('status', 'draft');
$fake->allow('edit', Post::class)->whereColumn('author_id', 'id');
$fake->allow('publish')->inScope(5);                    // only inside tenant 5
$fake->allow('*', '*');                                 // everything, everywhere
```

Ownership, conditions and tenancy are decided by the same pieces the database engine uses, and a test suite asserts the fake and the engine answer alike across the shapes a rule can take. Narrowing before scripting a rule throws.

> 📌 **The fake is not looser than the engine.** A rule with no entity answers entity-less checks only, a condition abstains where it has no instance to read, and an unscripted check abstains so your app's policies still decide. Where the fake cannot express something, it denies rather than granting.

### WithPermissions trait

```php
use ElPandaPe\Warden\Testing\WithPermissions;

$this->allowUser($user, 'view', Document::class);
$this->assignRoles($user, 'admin');
```

### Artisan commands

```bash
php artisan warden:show [Class:id]       # Show permissions for an authority
php artisan warden:cache-reset           # Reset cache
php artisan warden:clean --dry-run       # Clean orphaned permissions
php artisan warden:retitle --dry-run     # Converge titles an older Warden wrote
php artisan warden:doctor                # Audit the catalog for rules that can never be true
```

> 📌 **`warden:doctor` exits non-zero when it finds something**, so it works as a CI gate. It
> reads every stored condition back through the rule the write path enforces and reports the
> ones that would be refused today, with the permission and how many grants and forbids point
> at it. It changes nothing: adding the missing cast and rewriting the condition mean different
> things, and only you know which one you meant.

---

## 🛡️ Middleware & Blade

Off by default. Enable via config:

```php
'warden.register_middleware_aliases' => true,
'warden.register_blade_directives' => true,
```

### Middleware

```php
Route::get('/admin', ...)->middleware('warden.role:admin,editor');      // any of
Route::put('/site', ...)->middleware('warden.permission:edit-site');    // all of
```

> 📌 **`warden.role` answers exactly like `isA()`.** An assignment past its end date does not count, and with `warden.roles.nested` on, a role reached through another one does. A denial throws `UnauthorizedException`, which Laravel renders as a 403.

### Blade

```blade
@forbidden('publish')
    You are explicitly banned from publishing.
@endforbidden
```

---

## 🏗️ Schema & Models

Four tables:

| Table | Purpose |
|---|---|
| `permissions` | The catalog |
| `roles` | Role definitions |
| `assigned_roles` | Role ↔ authority pivot |
| `grants` | Permission ↔ authority (with `forbidden` flag) |

> 📌 **Revoking removes the grant, never the catalog row.** The row is shared, so pruning it inline would destroy a rule other holders point at. `warden:clean` is the supported way to reclaim rows nothing points at, and `--duplicates` collapses rows that identify the same permission.

> 📌 **Both pivot relations mix granted and forbidden rows.** `$role->permissions()` and `$permission->roles()` return every pivot row, whichever polarity it carries — filter to read one side:
>
> ```php
> $role->permissions()->wherePivot('forbidden', false)->get();   // what it can do
> $role->permissions()->wherePivot('forbidden', true)->get();    // what it is denied
> ```

> 📌 **Load the whole permission row before changing what identifies it.** `entity_type`, `entity_id`, `only_owned`, `scope` and `options` make up its identity key, so changing any of them on a permission fetched with a partial `select()` throws a `ConfigurationException` instead of saving a key computed from half a row. A permission created in the same request counts as whole: what it left unset holds the column default. An edit that leaves those five alone — its title, say — still saves, in strict mode too, and a save never moves an existing row into the active tenant: the tenant is stamped on creation only.

> 📌 **Titles are generated once, on creation, and only when none was given.** A rename keeps the old title, and setting `title` to `null` on an update leaves it `null`. Recompute one deliberately with `Support\Titles\PermissionTitle::generate()` or `RoleTitle::generate()` — the same calls the hook makes.

> 📌 **Ask before you rewrite a title.** `PermissionTitle::generations()` and `RoleTitle::generations()` return every title Warden could have written for a name, current first — each generator this package has published is transcribed and frozen. A stored title inside that list was Warden's; one outside it was typed by a person and is not yours to overwrite.
>
> ```php
> PermissionTitle::generations('viewAny', Post::class, null, false);
> // ['View any posts', 'ViewAny posts']  ← current, then the pre-2.0 reading
> ```
>
> `php artisan warden:retitle` applies exactly that rule across the catalogue: a title an older Warden generated converges on the current wording, a title someone wrote stays, and a `null` stays `null`. Run it with `--dry-run` first.

Any model can hold roles and permissions:

```php
use ElPandaPe\Warden\Concerns\HasRolesAndPermissions;

class User extends Authenticatable
{
    use HasRolesAndPermissions;
}
```

### Swap models via config

```php
// config/warden.php
'models' => [
    'role' => App\Models\Role::class,
],
```

```php
// app/Models/Role.php
class Role extends Model
{
    use ElPandaPe\Warden\Models\Concerns\IsRole;
}
```

> 📌 **Never** hardcode package classes in relations. Always resolve via config.

---

## ⚙️ Configuration

Everything lives in `config/warden.php`:

| Section | Controls |
|---|---|
| `models` | Swappable Role, Permission, Grant, AssignedRole models |
| `tables` | Table names and database connection |
| `morphs` | Morph aliases (`warden.role`, `warden.permission`) |
| `gate` | Gate behavior (`run_before_policies`, `register`) |
| `ownership` | Global/per-class ownership attribute |
| `scope` | Multi-tenancy semantics |
| `cache` | Store, prefix, TTL |
| `events` | Enable/disable events, cancellable pre-action events |
| `exceptions` | Display permission/role names in messages |

---

## 📖 Recipes

### Authorize someone other than the current user

```php
Gate::forUser($tenantUser)->allows('edit', $post);
Warden::explain($tenantUser, 'edit', $post);
```

### Ownership through a pivot table

```php
Warden::ownedVia(Business::class, fn ($business, $user) =>
    $business->owners()->whereKey($user->getKey())->exists()
);
Warden::allow($user)->toOwn(Business::class, ['manage']);
```

> ⚠️ Closure-resolved ownership cannot compile into `whereCan()`.

### Default role for new users

```php
// In your User model or observer:
protected static function booted(): void
{
    static::created(fn (User $user) => Warden::assign('member')->to($user));
}
```

> 💡 There is no "role for everyone" by design. Use `Warden::allowEveryone()->to(...)` for global grants.

### Landlord vs tenant databases

Point warden tables at their own connection with `warden.connection`. The published migration honors it (`Schema::connection(...)`), and the migration class is anonymous to avoid collisions.

### Replace a role instead of stacking

```php
Warden::sync($user)->roles(['editor']);     // declarative
Warden::retract('viewer')->from($user);       // or surgical
Warden::assign('editor')->to($user);
```

### Long-lived processes (Tinker, Octane, queues)

Writes through the API invalidate caches automatically. Only raw DB edits need `Warden::refresh()`. Tenant state lives in container-scoped bindings, so Octane requests and queue jobs reset themselves.

---

## 🔄 Migrating from silber/bouncer

```bash
composer require elpandape/warden        # replaces silber/bouncer (conflict enforced)
php artisan warden:upgrade --dry-run       # report
php artisan warden:upgrade                 # in-place schema transform
vendor/bin/rector process app --config vendor/elpandape/warden/stubs/rector-silber-upgrade.php
```

The fluent API is intentionally compatible. The schema upgrades in place (`abilities` → `permissions`, `permissions` pivot → `grants`). See **MIGRATING-FROM-BOUNCER.md** for the full equivalence table.

---

## 🧪 Development

No local PHP or Composer needed — everything runs through Docker:

```bash
make build      # build the dev image
make install    # composer install
make ci         # pint + phpstan + rector + tests (100% coverage) + type coverage
make test-dbs   # run suite against MySQL 9 and Postgres 16
make mutation   # mutation testing over the core
make shell      # shell inside the container
```

---

## 👤 Credits & License

- **Original concept & API design:** [Joseph Silber](https://github.com/JosephSilber) — this project started as an evolution of his [Bouncer](https://github.com/JosephSilber/bouncer) and keeps his copyright notice.
- **Maintainer:** [Carlos Mayorga](https://github.com/elpandape)

Licensed under the [MIT License](LICENSE.md).

---

<p align="center">
  <sub>Authorization that explains itself.</sub>
</p>
