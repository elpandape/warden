<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Events\Concerns;

use Illuminate\Contracts\Database\ModelIdentifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Queues the actor as an identifier, never by value, so a queued payload holds
 * none of its columns, hidden ones included. An actor whose row is gone by the
 * time a queued listener runs comes back as an unsaved stand-in that only
 * knows its key. The host event uses SerializesModels, whose helpers do both.
 */
trait CarriesActorByIdentifier
{
    private function actorIdentifier(?Model $actor): ?ModelIdentifier
    {
        $identifier = $this->getSerializedPropertyValue($actor, withRelations: false);

        return $identifier instanceof ModelIdentifier ? $identifier : null;
    }

    private function restoreActor(?ModelIdentifier $identifier): ?Model
    {
        if (! $identifier instanceof ModelIdentifier) {
            return null;
        }

        try {
            return $this->restoreModel($identifier);
        } catch (ModelNotFoundException $gone) {
            $class = $gone->getModel();
            $standIn = new $class;
            $standIn->setAttribute($standIn->getKeyName(), $identifier->id);

            return $standIn;
        }
    }
}
