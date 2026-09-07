<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Testing;

use BackedEnum;
use Closure;
use DateTimeInterface;
use ElPandaPe\Warden\Checks\Verdict;
use ElPandaPe\Warden\Constraints\Builder;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Contracts\Resolver;
use ElPandaPe\Warden\Exceptions\ConfigurationException;
use ElPandaPe\Warden\Support\Name;
use ElPandaPe\Warden\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use PHPUnit\Framework\Assert;

/**
 * A resolver you script by hand: no tables, no cache — your app's policies
 * still apply wherever the fake abstains. Records every check for assertions.
 *
 * Ownership, conditions and tenancy are decided by the same pieces the
 * database engine uses, so a scripted rule means here what it means there.
 */
final class WardenFake implements Resolver
{
    /** @var list<array{authority: Model, permission: string, entity: Model|string|null, verdict: Verdict}> */
    private array $checks = [];

    /** @var list<Rule> */
    private array $rules = [];

    public function resolve(
        Model $authority,
        string $permission,
        Model|string|null $entity = null,
    ): Verdict {
        $verdict = $this->verdictFor($authority, $permission, $entity);

        $this->checks[] = [
            'authority' => $authority,
            'permission' => $permission,
            'entity' => $entity,
            'verdict' => $verdict,
        ];

        return $verdict;
    }

    /**
     * Script a grant: any authority, this permission (optionally per class).
     */
    public function allow(string|BackedEnum $permission, Model|string|null $entity = null): static
    {
        $this->rules[] = new Rule(Name::of($permission), $entity, forbidden: false);

        return $this;
    }

    public function forbid(string|BackedEnum $permission, Model|string|null $entity = null): static
    {
        $this->rules[] = new Rule(Name::of($permission), $entity, forbidden: true);

        return $this;
    }

    /**
     * End the rule just scripted at a moment, and refuse it on a forbid for
     * the same reason the engine does: a prohibition that expires by clock is
     * not a prohibition.
     */
    public function until(?DateTimeInterface $moment): static
    {
        if ($this->lastRule()->forbidden) {
            throw new ConfigurationException('A forbid does not expire: lift it with unforbid().');
        }

        $this->lastRule()->expiresAt = $moment;

        return $this;
    }

    /**
     * Narrow the rule just scripted to one authority.
     */
    public function for(Model $authority): static
    {
        $this->lastRule()->authority = $authority;

        return $this;
    }

    /**
     * Narrow the rule just scripted to entities the authority owns.
     */
    public function owned(bool $only = true): static
    {
        $this->lastRule()->onlyOwned = $only;

        return $this;
    }

    /**
     * Narrow the rule just scripted to one tenant scope.
     */
    public function inScope(int|string|null $scope): static
    {
        $this->lastRule()->scope = $scope;

        return $this;
    }

    public function where(string|Closure $column, mixed $operator = null, mixed $value = null): static
    {
        $builder = $this->lastRule()->constraints ??= new Builder;

        func_num_args() <= 2
            ? $builder->where($column, $operator)
            : $builder->where($column, $operator, $value);

        return $this;
    }

    public function whereColumn(string $column, string $operatorOrAuthorityColumn, ?string $authorityColumn = null): static
    {
        ($this->lastRule()->constraints ??= new Builder)
            ->whereColumn($column, $operatorOrAuthorityColumn, $authorityColumn);

        return $this;
    }

    public function assertChecked(string|BackedEnum $permission): void
    {
        Assert::assertNotEmpty(
            $this->checksNamed(Name::of($permission)),
            'Expected permission ['.Name::of($permission).'] to have been checked, but it was not.',
        );
    }

    public function assertNotChecked(string|BackedEnum $permission): void
    {
        Assert::assertSame(
            [],
            $this->checksNamed(Name::of($permission)),
            'Expected permission ['.Name::of($permission).'] not to have been checked, but it was.',
        );
    }

    public function assertNothingChecked(): void
    {
        Assert::assertSame([], $this->checks, 'Expected no permission checks, but some ran.');
    }

    public function assertGranted(string|BackedEnum $permission): void
    {
        Assert::assertTrue(
            array_any(
                $this->checksNamed(Name::of($permission)),
                fn (array $check): bool => $check['verdict']->isGranted(),
            ),
            'Expected a granted check for ['.Name::of($permission).'], found none.',
        );
    }

    public function assertForbidden(string|BackedEnum $permission): void
    {
        Assert::assertTrue(
            array_any(
                $this->checksNamed(Name::of($permission)),
                fn (array $check): bool => $check['verdict']->isForbidden(),
            ),
            'Expected a forbidden check for ['.Name::of($permission).'], found none.',
        );
    }

    private function lastRule(): Rule
    {
        $rule = $this->rules[array_key_last($this->rules) ?? -1] ?? null;

        if (! $rule instanceof Rule) {
            throw new LogicException('Script a rule with allow() or forbid() before narrowing it.');
        }

        return $rule;
    }

    private function verdictFor(Model $authority, string $permission, Model|string|null $entity): Verdict
    {
        // A string that is not a model class belongs to app policies: abstain.
        if (is_string($entity) && $entity !== '*' && ! is_subclass_of($entity, Model::class)) {
            return Verdict::abstained();
        }

        $owned = $entity instanceof Model && Context::resolve()->isOwnedBy($authority, $entity);
        $filter = app(Tenancy::class)->readFilter();

        $matching = array_values(array_filter(
            $this->rules,
            fn (Rule $rule): bool => $rule->answersFor($authority, $permission, $entity, $owned, $filter),
        ));

        // Specificity first, then forbidden-first, like the database engine.
        usort($matching, fn (Rule $a, Rule $b): int => $b->specificity() <=> $a->specificity());

        foreach ([true, false] as $forbidden) {
            foreach ($matching as $rule) {
                if ($rule->forbidden === $forbidden && $rule->conditionsPass($entity, $authority)) {
                    return $forbidden ? Verdict::forbidden('fake') : Verdict::granted('fake');
                }
            }
        }

        return Verdict::abstained();
    }

    /**
     * @return list<array{authority: Model, permission: string, entity: Model|string|null, verdict: Verdict}>
     */
    private function checksNamed(string $permission): array
    {
        return array_values(array_filter(
            $this->checks,
            fn (array $check): bool => $check['permission'] === $permission,
        ));
    }
}
