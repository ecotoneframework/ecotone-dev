<?php

declare(strict_types=1);

namespace Ecotone\Tempest;

use Ecotone\Modelling\StandardRepository;
use Tempest\Database\IsDatabaseModel;

/**
 * licence Apache-2.0
 */
final class TempestRepository implements StandardRepository
{
    public function canHandle(string $aggregateClassName): bool
    {
        return $this->classUsesTrait($aggregateClassName, IsDatabaseModel::class);
    }

    public function findBy(string $aggregateClassName, array $identifiers): ?object
    {
        return $aggregateClassName::findById(array_pop($identifiers));
    }

    public function save(array $identifiers, object $aggregate, array $metadata, ?int $versionBeforeHandling): void
    {
        $aggregate->save();
    }

    private function classUsesTrait(string $className, string $traitName): bool
    {
        foreach ($this->collectAllTraits($className) as $usedTrait) {
            if ($usedTrait === $traitName) {
                return true;
            }
        }

        return false;
    }

    private function collectAllTraits(string $className): array
    {
        $traits = [];

        $class = $className;
        while ($class !== false) {
            $traits = array_merge($traits, $this->collectTraitsRecursively(class_uses($class) ?: []));
            $class = get_parent_class($class);
        }

        return $traits;
    }

    private function collectTraitsRecursively(array $traits): array
    {
        $collected = array_values($traits);

        foreach ($traits as $trait) {
            $nestedTraits = class_uses($trait) ?: [];
            if ($nestedTraits !== []) {
                $collected = array_merge($collected, $this->collectTraitsRecursively($nestedTraits));
            }
        }

        return $collected;
    }
}
