<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Actions\Concerns;

use ElPandaPe\Warden\Exceptions\ConfigurationException;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

trait ValidatesModels
{
    protected function modelKey(Model $model): int|string
    {
        $key = $model->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw new InvalidArgumentException(
                $model::class.' must expose an int or string key.',
            );
        }

        return $key;
    }

    /**
     * A row written for a model that is not saved names a record that does not
     * exist, and would apply to whichever one is later saved under its key.
     */
    protected function assertSavedAuthority(Model $authority): Model
    {
        if (! $authority->exists) {
            throw new ConfigurationException('The authority must be a saved model with a usable key.');
        }

        return $this->assertKeyedAuthority($authority);
    }

    /**
     * The key is all a stored row has to name its holder by.
     */
    protected function assertKeyedAuthority(Model $authority): Model
    {
        $key = $authority->getKey();
        $usable = is_int($key) || (is_string($key) && $key !== '');

        if (! $usable) {
            throw new ConfigurationException('The authority must be a saved model with a usable key.');
        }

        return $authority;
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $expected
     * @return TModel
     */
    protected function assertModelOf(Model $model, string $expected, string $role): Model
    {
        if (! $model instanceof $expected) {
            throw new InvalidArgumentException(
                'The given '.$model::class." is not the configured {$role} model [{$expected}].",
            );
        }

        return $model;
    }
}
