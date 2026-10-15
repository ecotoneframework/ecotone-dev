<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Database;

use Ecotone\Api\Attribute\ConsoleCommand;
use Ecotone\Api\Attribute\ConsoleParameterOption;
use Ecotone\Api\Dbal\ExtensionObject\DatabaseSetupManagerRegistry;
use Ecotone\Messaging\Config\ConsoleCommandResultSet;
use InvalidArgumentException;

use function is_bool;

/**
 * Console command handler for database setup operations.
 *
 * licence Apache-2.0
 */
class DatabaseSetupCommand
{
    public function __construct(
        private DatabaseSetupManagerRegistry $databaseSetupManagerRegistry,
    ) {
    }

    #[ConsoleCommand('ecotone:migration:database:setup')]
    public function setup(
        #[ConsoleParameterOption] array $feature = [],
        #[ConsoleParameterOption] bool|string $initialize = false,
        #[ConsoleParameterOption] bool|string $sql = false,
        #[ConsoleParameterOption] bool|string $onlyUsed = true,
        #[ConsoleParameterOption] bool|string $missing = false,
        #[ConsoleParameterOption] ?string $connection = null,
    ): ?ConsoleCommandResultSet {
        $initialize = $this->normalizeBoolean($initialize);
        $sql = $this->normalizeBoolean($sql);
        $onlyUsed = $this->normalizeBoolean($onlyUsed);
        $missing = $this->normalizeBoolean($missing);

        $connectionReferenceNames = $this->resolveConnectionScope($connection);

        if (count($feature) > 0) {
            return $this->setupForFeatures($feature, $connectionReferenceNames, $initialize, $sql, $missing);
        }

        return $this->setupForAllFeatures($connectionReferenceNames, $onlyUsed, $initialize, $sql, $missing);
    }

    private function setupForFeatures(array $featureNames, array $connectionReferenceNames, bool $initialize, bool $sql, bool $missing): ConsoleCommandResultSet
    {
        $matches = $this->locateFeatures($featureNames, $connectionReferenceNames);

        if ($missing) {
            $matches = array_values(array_filter(
                $matches,
                fn (array $match) => ! ($this->databaseSetupManagerRegistry->getManagerFor($match['connection'])->getInitializationStatus()[$match['feature']] ?? false)
            ));
        }

        if ($sql) {
            $statements = [];
            foreach ($matches as $match) {
                $statements = array_merge(
                    $statements,
                    $this->databaseSetupManagerRegistry->getManagerFor($match['connection'])->getCreateSqlStatementsForFeatures([$match['feature']])
                );
            }
            return ConsoleCommandResultSet::create(['SQL Statement'], [[implode("\n", $statements)]]);
        }

        if ($initialize) {
            $rows = [];
            foreach ($matches as $match) {
                $this->databaseSetupManagerRegistry->getManagerFor($match['connection'])->initialize($match['feature']);
                $rows[] = [$match['feature'], $match['connection'], 'Created'];
            }
            return ConsoleCommandResultSet::create(['Feature', 'Connection', 'Status'], $rows);
        }

        $rows = [];
        foreach ($matches as $match) {
            $manager = $this->databaseSetupManagerRegistry->getManagerFor($match['connection']);
            $isInitialized = $manager->getInitializationStatus()[$match['feature']] ?? false;
            $isUsed = $manager->getUsageStatus()[$match['feature']] ?? false;
            $rows[] = [$match['feature'], $match['connection'], $isUsed ? 'Yes' : 'No', $isInitialized ? 'Yes' : 'No'];
        }
        return ConsoleCommandResultSet::create(['Feature', 'Connection', 'Used', 'Initialized'], $rows);
    }

    private function setupForAllFeatures(array $connectionReferenceNames, bool $onlyUsed, bool $initialize, bool $sql, bool $missing): ConsoleCommandResultSet
    {
        $entries = $this->collectFeatureEntries($connectionReferenceNames, $onlyUsed);

        if (count($entries) === 0) {
            return ConsoleCommandResultSet::create(
                ['Status'],
                [['No database tables registered for setup.']]
            );
        }

        if ($missing) {
            $entries = array_values(array_filter($entries, fn (array $entry) => ! $entry['initialized']));
        }

        if ($sql) {
            $statements = [];
            foreach ($entries as $entry) {
                $statements = array_merge(
                    $statements,
                    $this->databaseSetupManagerRegistry->getManagerFor($entry['connection'])->getCreateSqlStatementsForFeatures([$entry['feature']])
                );
            }
            return ConsoleCommandResultSet::create(['SQL Statement'], [[implode("\n", $statements)]]);
        }

        if ($missing) {
            return ConsoleCommandResultSet::create(
                ['Feature', 'Connection'],
                array_map(fn (array $entry) => [$entry['feature'], $entry['connection']], $entries)
            );
        }

        if ($initialize) {
            foreach ($connectionReferenceNames as $connectionReferenceName) {
                $this->databaseSetupManagerRegistry->getManagerFor($connectionReferenceName)->initializeAll($onlyUsed);
            }
            return ConsoleCommandResultSet::create(
                ['Feature', 'Connection', 'Status'],
                array_map(fn (array $entry) => [$entry['feature'], $entry['connection'], 'Created'], $entries)
            );
        }

        return ConsoleCommandResultSet::create(
            ['Feature', 'Connection', 'Used', 'Initialized'],
            array_map(
                fn (array $entry) => [$entry['feature'], $entry['connection'], $entry['used'] ? 'Yes' : 'No', $entry['initialized'] ? 'Yes' : 'No'],
                $entries
            )
        );
    }

    /**
     * @param string[] $connectionReferenceNames
     * @return array<array{feature: string, connection: string, used: bool, initialized: bool}>
     */
    private function collectFeatureEntries(array $connectionReferenceNames, bool $onlyUsed): array
    {
        $entries = [];
        foreach ($connectionReferenceNames as $connectionReferenceName) {
            $manager = $this->databaseSetupManagerRegistry->getManagerFor($connectionReferenceName);
            $initializationStatus = $manager->getInitializationStatus($onlyUsed);
            $usageStatus = $manager->getUsageStatus();
            foreach ($manager->getFeatureNames($onlyUsed) as $featureName) {
                $entries[] = [
                    'feature' => $featureName,
                    'connection' => $connectionReferenceName,
                    'used' => $usageStatus[$featureName] ?? false,
                    'initialized' => $initializationStatus[$featureName] ?? false,
                ];
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
