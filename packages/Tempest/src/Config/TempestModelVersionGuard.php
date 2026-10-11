<?php

declare(strict_types=1);

namespace Ecotone\Tempest\Config;

use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\Saga;
use Ecotone\Api\Attribute\Version;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Tempest\TempestRepository;
use Tempest\Database\IsDatabaseModel;

/**
 * licence Apache-2.0
 */
final class TempestModelVersionGuard
{
    /**
     * @param class-string[] $aggregateClasses
     */
    public static function assertNoVersionedTempestModel(array $aggregateClasses, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $tempestRepository = new TempestRepository();
        foreach ($aggregateClasses as $aggregateClass) {
            if (! $tempestRepository->canHandle($aggregateClass)) {
                continue;
            }

            $classDefinition = $interfaceToCallRegistry->getClassDefinitionFor(Type::object($aggregateClass));
            if ($classDefinition->hasClassAnnotation(Type::attribute(EventSourcingAggregate::class))) {
                continue;
            }

            foreach ($classDefinition->getPropertiesWithAnnotation(Type::attribute(Version::class)) as $versionProperty) {
                $kind = $classDefinition->hasClassAnnotation(Type::attribute(Saga::class)) ? 'Saga' : 'Aggregate';
                $property = $versionProperty->getName();

                throw ConfigurationException::create(
                    "{$kind} {$aggregateClass} is stored as a Tempest model (it uses " . IsDatabaseModel::class . ") and declares #[Version] on \${$property}, so its saves would be version-checked. "
                    . "A version check needs to know whether `UPDATE ... WHERE id = ? AND version = ?` matched a row, and Tempest's database API does not report whether an UPDATE matched a row, so a stale save would silently overwrite a newer one. "
                    . "Either remove #[Version] from \${$property} and accept that the last write wins, "
                    . "or stop making {$aggregateClass} a Tempest model and store it through the DBAL document-store repository, DbalConfiguration::withDocumentStore(enableDocumentStoreStateStoredRepository: true), where #[Version] is checked, "
                    . 'or make it an #[EventSourcingAggregate], whose stream version is checked on every append.'
                );
            }
        }
    }
}
