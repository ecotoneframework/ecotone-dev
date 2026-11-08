<?php

declare(strict_types=1);

namespace Ecotone\JMSConverter;

use Metadata\ClassMetadata;
use Metadata\Driver\DriverInterface;
use ReflectionClass;

/**
 * licence Apache-2.0
 */
final class TraitFilesTrackingDriver implements DriverInterface
{
    public function __construct(private DriverInterface $driver)
    {
    }

    public function loadMetadataForClass(ReflectionClass $class): ?ClassMetadata
    {
        $metadata = $this->driver->loadMetadataForClass($class);
        if ($metadata === null) {
            return null;
        }

        foreach ($this->traitFilesOf($class) as $traitFile) {
            if (! in_array($traitFile, $metadata->fileResources, true)) {
                $metadata->fileResources[] = $traitFile;
            }
        }

        return $metadata;
    }

    /**
     * @return string[]
     */
    private function traitFilesOf(ReflectionClass $class): array
    {
        $traitFiles = [];
        foreach ($class->getTraits() as $trait) {
            if ($trait->getFileName() !== false) {
                $traitFiles[] = $trait->getFileName();
            }
            $traitFiles = [...$traitFiles, ...$this->traitFilesOf($trait)];
        }

        return $traitFiles;
    }
}
