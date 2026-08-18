# Contributing

Thanks for considering a contribution.

## Ground rules

- **No local PHP or Composer needed.** Everything runs through Docker via `make`.
  If a command in this file is not a `make` target, it is a bug in this file.
- **The public API is frozen at 1.0.** Additive changes are welcome; anything that
  breaks a documented signature, config key or behavior waits for a major.
- **Fail closed.** When a check cannot be decided, the answer is "no". Corrupt data,
  missing attributes and inexpressible conditions must never widen a grant.

## Getting started

```bash
make build      # build the dev image
make install    # composer install
make ci         # everything CI runs, in one command
```

Useful targets: `make test` (parallel), `make test-dbs` (MySQL 9 + Postgres 16),
`make mutation` (mutation testing over the core), `make lint-fix`, `make rector-fix`,
`make shell`.

## What a change needs before it merges

- `make ci` green: Pint, PHPStan at max, Rector dry-run, the suite against **both**
  resolvers, and **100% coverage and type coverage**. These are gates, not goals.
- `make test-dbs` green when the change touches SQL, the schema, or scoping.
- A test that fails without the change. For anything security-shaped (forbids,
  scoping, ownership, cache invalidation), a test that proves it fails *closed*.
- Comments only where the code cannot speak for itself, in English.

## Commits

Conventional Commits in English, atomic, with a real module scope for
`feat`/`fix`/`refactor`/`perf`/`test`:

```
feat(resolver): honor ownership and tenancy

- what changed, and why it matters
- no filenames: the diff already lists them
```

## Reporting bugs

Include the Laravel and PHP versions, the database engine, and — most useful of all —
the output of `Warden::explain($user, 'permission', $entity)`, which names the exact
row and role behind a verdict.

Security issues: see [SECURITY.md](SECURITY.md). Do not open a public issue.
