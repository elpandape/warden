<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Checks\Explain;

use ElPandaPe\Warden\Checks\Verdict;
use Illuminate\Database\Eloquent\Model;
use Stringable;

/**
 * The "why" behind a check: the verdict, its cause, and the decisive rows.
 * Answers the tracker's most repeated question — including "you are
 * explicitly forbidden", which no other package can say.
 */
final readonly class AuthorizationExplanation implements Stringable
{
    public function __construct(
        public Verdict $verdict,
        public Cause $cause,
        public ?Model $permission = null,
        public ?Model $role = null,
    ) {}

    public function __toString(): string
    {
        $name = $this->permission?->getAttribute('name');
        $subject = is_string($name) ? "permission [{$name}]" : 'no permission';
        $roleName = $this->role?->getAttribute('name');
        $via = is_string($roleName) ? " via role [{$roleName}]" : '';

        return match ($this->cause) {
            Cause::GrantedDirectly => "Granted by {$subject}, held directly.",
            Cause::GrantedViaRole => "Granted by {$subject}{$via}.",
            Cause::GrantedToEveryone => "Granted by {$subject}, given to everyone.",
            Cause::ForbiddenDirectly => "Explicitly forbidden by {$subject}, held directly.",
            Cause::ForbiddenViaRole => "Explicitly forbidden by {$subject}{$via}.",
            Cause::ForbiddenToEveryone => "Explicitly forbidden by {$subject}, applied to everyone.",
            Cause::NoMatchingGrant => 'No matching grant: Warden abstains, app policies decide.',
            Cause::NotApplicable => 'Not a Warden question: the entity is not a model.',
        };
    }

    public function allowed(): bool
    {
        return $this->verdict->isGranted();
    }
}
