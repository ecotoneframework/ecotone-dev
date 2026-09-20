<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Database;

use Ecotone\Api\Attribute\ConsoleCommand;
use Ecotone\Api\Attribute\ConsoleParameterOption;
use Ecotone\Api\Dbal\ExtensionObject\DatabaseSetupManagerRegistry;
use Ecotone\Messaging\Config\ConsoleCommandResultSet;
use InvalidArgumentException;

/**
 * Console command handler for database delete operations.
 *
 * licence Apache-2.0
 */
class DatabaseDeleteCommand
{
    public function __construct(
        private DatabaseSetupManagerRegistry $databaseSetupManagerRegistry,
    ) {
    }

    #[ConsoleCommand('ecotone:migration:database:delete')]
    public function delete(
        #[ConsoleParameterOption] array $feature = [],
        #[ConsoleParameterOption] bool|string $force = false,
        #[ConsoleParameterOption] bool|string $onlyUsed = true,
        #[ConsoleParameterOption] ?string $connection = null,
    ): ?ConsoleCommandResultSet {
        $force = $this->normalizeBoolean($force);
        $onlyUsed = $this->normalizeBoolean($onlyUsed);

        $connectionReferenceNames = $this->resolveConnectionScope($connection);

        if (count($feature) > 0) {
            return $this->deleteFeatures($feature, $connectionReferenceNames, $force);
        }

        return $this->deleteAllFeatures($connectionReferenceNames, $onlyUsed, $force);
    }

    private function deleteFeatures(array $featureNames, array $connectionReferenceNames, bool $force): ConsoleCommandResultSet
    {
        $matches = $this->locateFeatures($featureNames, $connectionReferenceNames);
        $rows = [];

        if (! $force) {
            foreach ($matches as $match) {
                $rows[] = [$match['feature'], $match['connection'], 'Would be deleted (use --force to confirm)'];
            }
            return ConsoleCommandResultSet::create(['Feature', 'Connection', 'Warning'], $rows);
        }

        foreach ($matches as $match) {
            $this->databaseSetupManagerRegistry->getManagerFor($match['connection'])->drop($match['feature']);
            $rows[] = [$match['feature'], $match['connection'], 'Deleted'];
        }
        return ConsoleCommandResultSet::create(['Feature', 'Connection', 'Status'], $rows);
    }

    private function deleteAllFeatures(array $connectionReferenceNames, bool $onlyUsed, bool $force): ConsoleCommandResultSet
    {
        $entries = $this->collectFeatureEntries($connectionReferenceNames, $onlyUsed);

        if (count($entries) === 0) {
            return ConsoleCommandResultSet::create(
                ['Status'],
                [['No database tables registered for deletion.']]
            );
        }

        if (! $force) {
            return ConsoleCommandResultSet::create(
                ['Feature', 'Connection', 'Warning'],
                array_map(fn (array $entry) => [$entry['feature'], $entry['connection'], 'Would be deleted (use --force to confirm)'], $entries)
            );
        }

        foreach ($connectionReferenceNames as $connectionReferenceName) {
            $this->databaseSetupManagerRegistry->getManagerFor($connectionReferenceName)->dropAll($onlyUsed);
        }
        return ConsoleCommandResultSet::create(
            ['Feature', 'Connection', 'Status'],
            array_map(fn (array $entry) => [$entry['feature'], $entry['connection'], 'Deleted'], $entries)
        );
    }

    /**
     * @param string[] $connectionReferenceNames
     * @return array<array{feature: string, connection: string}>
     */
    private function collectFeatureEntries(array $connectionReferenceNames, bool $onlyUsed): array
    {
        $entries = [];
        foreach ($connectionReferenceNames as $connectionReferenceName) {
            $manager = $this->databaseSetupManagerRegistry->getManagerFor($connectionReferenceName);
            foreach ($manager->getFeatureNames($onlyUsed) as $featureName) {
                $entries[] = ['feature' => $featureName, 'connection' => $connectionReferenceName];
            }
        }

        return $entries;
    }

    /**
     * @param string[] $featureNames
     * @param string[] $connectionReferenceNames
     * @return array<array{feature: string, connection: string}>
     */
    private function locateFeatures(array $featureNames, array $connectionReferenceNames): array
    {
        $matches = [];
        $found = [];
        foreach ($connectionReferenceNames as $connectionReferenceName) {
            $manager = $this->databaseSetupManagerRegistry->getManagerFor($connectionReferenceName);
            foreach ($featureNames as $featureName) {
                if (in_array($featureName, $manager->getFeatureNames(false), true)) {
                    $matches[] = ['feature' => $featureName, 'connection' => $connectionReferenceName];
                    $found[$featureName] = true;
                }
            }
        }

        $notFound = array_diff($featureNames, array_keys($found));
        if ($notFound !== []) {
            throw new InvalidArgumentException(sprintf(
                'Table manager not found for feature(s): %s on connection(s): %s',
                implode(', ', $notFound),
                implode(', ', $connectionReferenceNames)
            ));
        }

        return $matches;
    }

    /**
     * @return string[]
     */
    private function resolveConnectionScope(?string $connection): array
    {
        if ($connection !== null) {
            $this->databaseSetupManagerRegistry->getManagerFor($connection);

            return [$connection];
        }

        return $this->databaseSetupManagerRegistry->getConnectionReferenceNames();
    }

    private function normalizeBoolean(bool|string $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return $value !== 'false' && $value !== '0' && $value !== '';
    }
}
