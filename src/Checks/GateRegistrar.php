<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Checks;

use ElPandaPe\Bouncer\Contracts\Resolver;
use ElPandaPe\Bouncer\Enums\GateSlot;
use ElPandaPe\Bouncer\Support\Config;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\Model;

final readonly class GateRegistrar
{
    public function __construct(private Resolver $resolver) {}

    public function registerAt(Gate $gate): void
    {
        $gate->before(fn (mixed $user, string $ability, array $arguments = []): Response|bool|null => Config::gateSlot() === GateSlot::Before
            ? $this->evaluate($user, $ability, $arguments)
            : null);

        $gate->after(function (mixed $user, string $ability, mixed $result, array $arguments = []): Response|bool|null {
            // In the after slot the app's policies and definitions win.
            if (Config::gateSlot() === GateSlot::Before || $result !== null) {
                return null;
            }

            return $this->evaluate($user, $ability, $arguments);
        });
    }

    /**
     * True grants, a deny response is a hard forbid that cuts the gate, null abstains.
     *
     * @param  array<array-key, mixed>  $arguments
     */
    private function evaluate(mixed $user, string $ability, array $arguments): Response|bool|null
    {
        // Read live so runtime reconfiguration behaves like its sibling keys.
        if (! Config::gateRegisters()) {
            return null;
        }

        if (! $user instanceof Model) {
            return null;
        }

        $arguments = array_values($arguments);

        // Checks with extra arguments belong to app policies, never to Bouncer.
        if (count($arguments) > 1) {
            return null;
        }

        $entity = $arguments[0] ?? null;

        if ($entity !== null && ! $entity instanceof Model && ! is_string($entity)) {
            return null;
        }

        $verdict = $this->resolver->resolve($user, $ability, $entity);

        return match (true) {
            $verdict->isForbidden() => Response::deny($this->denialMessage()),
            $verdict->isGranted() => true,
            default => null,
        };
    }

    private function denialMessage(): string
    {
        $message = trans('bouncer::bouncer.unauthorized');

        return is_string($message) ? $message : 'This action is unauthorized.';
    }
}
