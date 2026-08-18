<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Checks;

use ElPandaPe\Warden\Contracts\Resolver;
use ElPandaPe\Warden\Enums\GateSlot;
use ElPandaPe\Warden\Support\Config;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\Model;

final readonly class GateRegistrar
{
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

        // Checks with extra arguments belong to app policies, never to Warden.
        if (count($arguments) > 1) {
            return null;
        }

        $entity = $arguments[0] ?? null;

        if ($entity !== null && ! $entity instanceof Model && ! is_string($entity)) {
            return null;
        }

        // Resolved per check: scoped bindings hand Octane a fresh resolver
        // (and its memoization) on every request.
        $verdict = app(Resolver::class)->resolve($user, $ability, $entity);

        return match (true) {
            $verdict->isForbidden() => Response::deny($this->denialMessage()),
            $verdict->isGranted() => true,
            default => null,
        };
    }

    private function denialMessage(): string
    {
        $message = trans('warden::warden.unauthorized');

        return is_string($message) ? $message : 'This action is unauthorized.';
    }
}
