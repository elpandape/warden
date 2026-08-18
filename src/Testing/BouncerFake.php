<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Testing;

use BackedEnum;
use ElPandaPe\Bouncer\Checks\Verdict;
use ElPandaPe\Bouncer\Contracts\Resolver;
use ElPandaPe\Bouncer\Support\Name;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert;

/**
 * A resolver you script by hand: no tables, no cache — your app's policies
 * still apply wherever the fake abstains. Records every check for assertions.
 */
final class BouncerFake implements Resolver
{
    /** @var list<array{authority: Model, permission: string, entity: Model|string|null, verdict: Verdict}> */
    private array $checks = [];

    /** @var list<array{permission: string, entity: string|null, forbidden: bool}> */
    private array $rules = [];

    public function resolve(
        Model $authority,
        string $permission,
        Model|string|null $entity = null,
    ): Verdict {
        $verdict = $this->verdictFor($permission, $entity);

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
        $this->rules[] = [
            'permission' => Name::of($permission),
            'entity' => $this->entityClass($entity),
            'forbidden' => false,
        ];

        return $this;
    }

    public function forbid(string|BackedEnum $permission, Model|string|null $entity = null): static
    {
        $this->rules[] = [
            'permission' => Name::of($permission),
            'entity' => $this->entityClass($entity),
            'forbidden' => true,
        ];

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

    private function verdictFor(string $permission, Model|string|null $entity): Verdict
    {
        $matching = array_values(array_filter(
            $this->rules,
            fn (array $rule): bool => $rule['permission'] === $permission
                && $this->entityMatches($rule['entity'], $entity),
        ));

        // Forbidden-first, like the real engines.
        foreach ([true, false] as $forbidden) {
            foreach ($matching as $rule) {
                if ($rule['forbidden'] === $forbidden) {
                    return $forbidden ? Verdict::forbidden('fake') : Verdict::granted('fake');
                }
            }
        }

        return Verdict::abstained();
    }

    private function entityMatches(?string $ruleEntity, Model|string|null $entity): bool
    {
        if ($ruleEntity === null) {
            return true;
        }

        if ($entity instanceof Model) {
            return $entity instanceof $ruleEntity;
        }

        return $entity === $ruleEntity;
    }

    private function entityClass(Model|string|null $entity): ?string
    {
        return $entity instanceof Model ? $entity::class : $entity;
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
