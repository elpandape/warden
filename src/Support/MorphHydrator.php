<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Log;

/**
 * Names the rows that morph columns point at. It reads past global scopes on
 * purpose: it names what a write touched, it never authorizes.
 *
 * @internal
 */
final class MorphHydrator
{
    private const int BATCH = 500;

    /**
     * @param  iterable<array{string, int|string}>  $pairs
     * @return array<string, Model>
     */
    public static function many(iterable $pairs): array
    {
        /** @var array<string, list<int|string>> $idsByType */
        $idsByType = [];

        foreach ($pairs as [$type, $id]) {
            $idsByType[$type][] = $id;
        }

        $found = [];

        foreach ($idsByType as $type => $ids) {
            $type = (string) $type;
            $class = self::classOf($type);

            if ($class === null) {
                Log::warning("Warden: no model class maps the morph type [{$type}], so its rows cannot be named.", ['entity_type' => $type, 'ids' => $ids]);

                continue;
            }

            // A string key binds one parameter per id, and engines cap how many
            // parameters a single statement takes.
            foreach (array_chunk(array_values(array_unique($ids)), self::BATCH) as $batch) {
                foreach ($class::query()->withoutGlobalScopes()->whereKey($batch)->get() as $model) {
                    $key = $model->getKey();

                    if (is_int($key) || is_string($key)) {
                        $found[self::key($type, $key)] = $model;
                    }
                }
            }
        }

        return $found;
    }

    public static function key(string $type, int|string $id): string
    {
        return $type."\x1f".$id;
    }

    /**
     * An unsaved instance that only knows its key: what names a context whose
     * row is gone, where null would read as "no context at all".
     */
    public static function standIn(string $type, int|string $id): ?Model
    {
        $class = self::classOf($type);

        if ($class === null) {
            return null;
        }

        $model = new $class;
        $model->setAttribute($model->getKeyName(), $id);

        return $model;
    }

    /**
     * @return class-string<Model>|null
     */
    private static function classOf(string $type): ?string
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        return is_subclass_of($class, Model::class) ? $class : null;
    }
}
