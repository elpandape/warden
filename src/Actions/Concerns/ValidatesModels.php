<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Actions\Concerns;

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
