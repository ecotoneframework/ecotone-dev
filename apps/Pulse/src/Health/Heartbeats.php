<?php

declare(strict_types=1);

namespace App\Health;

use App\Settings\PulseSettings;
use App\Storage\InternalDatabase;
use App\Storage\InternalTables;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

final readonly class Heartbeats
{
    public const REFRESH = 'refresh';
    public const CONSUMER_PREFIX = 'consumer:';
    private const MINIMUM_STALE_SECONDS = 15;

    public function __construct(
        private InternalDatabase $database,
        private PulseSettings $settings,
        #[Autowire(service: 'pulse.clock')] private ClockInterface $clock,
    ) {
    }

    public function record(string $process): void
    {
        $this->database->inTransaction(fn ($connection) => $connection->executeStatement(
            'INSERT INTO ' . InternalTables::HEARTBEAT . ' (process, beat_at) VALUES (?, ?) ON CONFLICT(process) DO UPDATE SET beat_at = excluded.beat_at',
            [$process, $this->clock->now()->getTimestamp()]
        ));
    }

    /**
     * @return list<ReadinessCheck>
     */
    public function readiness(): array
    {
        $checks = [$this->internalDatabase()];
        foreach (array_keys($this->settings->replyChannels()) as $channel) {
            $checks[] = $this->process(
                'reply consumer ' . $channel,
                self::CONSUMER_PREFIX . $channel,
                max(3 * $this->settings->refreshIntervalSeconds, self::MINIMUM_STALE_SECONDS),
                sprintf('Check that "ecotone:run %s" is running in this container; Pulse receives no replies without it.', $channel)
            );
        }
        $checks[] = $this->process(
            'periodic refresh',
            self::REFRESH,
            3 * $this->settings->refreshIntervalSeconds,
            'Check that "pulse:refresh" is running in this container; snapshots go stale without it.'
        );

        return $checks;
    }

    private function internalDatabase(): ReadinessCheck
    {
        try {
            $this->record('readiness');
        } catch (Throwable $exception) {
            return new ReadinessCheck('internal database', false, sprintf('The internal SQLite database at %s is not writable: %s. Make the directory writable by the user Pulse runs as.', $this->settings->internalDatabasePath, $exception->getMessage()));
        }

        return new ReadinessCheck('internal database', true, 'writable');
    }

    private function process(string $name, string $process, int $staleAfterSeconds, string $fix): ReadinessCheck
    {
        try {
            $beatAt = $this->database->connection()->fetchOne('SELECT beat_at FROM ' . InternalTables::HEARTBEAT . ' WHERE process = ?', [$process]);
        } catch (Throwable) {
            $beatAt = false;
        }

        if ($beatAt === false) {
            return new ReadinessCheck($name, false, sprintf('%s has not reported yet. %s', $name, $fix));
        }

        $age = $this->clock->now()->getTimestamp() - (int) $beatAt;
        if ($age >= $staleAfterSeconds) {
            return new ReadinessCheck($name, false, sprintf('%s last reported %d seconds ago, more than the %d seconds it is allowed. %s', $name, $age, $staleAfterSeconds, $fix));
        }

        return new ReadinessCheck($name, true, sprintf('reported %d seconds ago', $age));
    }
}
