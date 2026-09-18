<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Exceptions;

/**
 * A write named a catalog row that sits in the trash. A new row beside it
 * would collide with it on the unique index, or, with a null scope, leave two
 * live rows under one identity the moment the old one is restored.
 */
final class TrashedCatalogRow extends ConfigurationException
{
    private const string REMEDY = 'is in the trash: restore it or force-delete it before writing it again.';

    public static function role(string $name): self
    {
        return new self("Role [{$name}] ".self::REMEDY);
    }

    public static function permission(string $name, ?string $entity = null, bool $onlyOwned = false, bool $withConditions = false): self
    {
        $on = $entity === null ? '' : ' on '.($onlyOwned ? 'owned ' : '')."[{$entity}]";
        $conditions = $withConditions ? ' with conditions' : '';

        return new self("Permission [{$name}]{$on}{$conditions} ".self::REMEDY);
    }
}
