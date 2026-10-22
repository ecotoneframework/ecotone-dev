<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Database\Pulse;

use Ecotone\Api\Dbal\ExtensionObject\DatabaseSetupManager;
use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseSnapshot;
use Throwable;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
 * @internal
 */
final class BudgetedSetupInspection
{
    private const NOT_CHECKED_IN_TIME = 'not checked within the budget';

    private int $startedAt;

    public function __construct(private int $budgetInMilliseconds = PulseSnapshot::PROBE_BUDGET_IN_MILLISECONDS)
    {
        $this->startedAt = hrtime(true);
    }

    /**
     * @return list<array{feature: string, connection: string, initialized: bool|null, error: string|null}>
     */
    public function of(DatabaseSetupManager $manager, string $connectionReferenceName, bool $onCurrentConnection): array
    {
        $tables = [];
        $failure = null;
        foreach ($manager->getFeatureNames() as $feature) {
            $remaining = $this->remainingMilliseconds();
            if ($failure === null && $remaining <= 0) {
                $failure = self::NOT_CHECKED_IN_TIME;
            }
            if ($failure !== null) {
                $tables[] = ['feature' => $feature, 'connection' => $connectionReferenceName, 'initialized' => null, 'error' => $failure];
                continue;
            }

            try {
                $initialized = $onCurrentConnection
                    ? $manager->isFeatureInitializedOnCurrentConnectionWithin($feature, $remaining)
                    : $manager->isFeatureInitializedWithin($feature, $remaining);
                $tables[] = ['feature' => $feature, 'connection' => $connectionReferenceName, 'initialized' => $initialized, 'error' => null];
            } catch (Throwable $exception) {
                $failure = $this->remainingMilliseconds() <= 0 ? self::NOT_CHECKED_IN_TIME : $exception->getMessage();
                $tables[] = ['feature' => $feature, 'connection' => $connectionReferenceName, 'initialized' => null, 'error' => $failure];
            }
        }

        return $tables;
    }

    private function remainingMilliseconds(): int
    {
        return $this->budgetInMilliseconds - (int) ((hrtime(true) - $this->startedAt) / 1_000_000);
    }
}
