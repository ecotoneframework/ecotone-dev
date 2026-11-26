<?php

declare(strict_types=1);

namespace App\Command;

use App\Health\Heartbeats;
use App\Settings\PulseSettings;
use App\Snapshot\Snapshots;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'pulse:refresh', description: 'Requests a snapshot from every service every PULSE_REFRESH_INTERVAL seconds')]
final class RefreshSnapshotsCommand extends Command implements SignalableCommandInterface
{
    private bool $stopped = false;

    public function __construct(
        private readonly Snapshots $snapshots,
        private readonly Heartbeats $heartbeats,
        private readonly PulseSettings $settings,
        #[Autowire(service: 'pulse.clock')] private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('ticks', null, InputOption::VALUE_REQUIRED, 'Stop after this many refresh ticks, 0 runs until stopped', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ticks = (int) $input->getOption('ticks');
        $done = 0;
        $nextTickAt = DateTimeImmutable::createFromInterface($this->clock->now());
        do {
            $this->tick($output);
            $done++;
            $nextTickAt = $nextTickAt->modify(sprintf('+%d seconds', $this->settings->refreshIntervalSeconds));
        } while (($ticks === 0 || $done < $ticks) && $this->waitUntil($nextTickAt));

        return Command::SUCCESS;
    }

    private function tick(OutputInterface $output): void
    {
        try {
            $this->snapshots->refreshIdleServices();
            $this->heartbeats->record(Heartbeats::REFRESH);
            $this->heartbeats->recordRunningConsumers((string) (getenv('PULSE_RUN_DIR') ?: '/tmp/pulse-run'));
        } catch (LockWaitTimeoutException $exception) {
            $this->logger->warning(
                'Pulse skipped a refresh tick: another process held the write lock of the internal database at {path} for more than {seconds} seconds. Pulse refreshes again on the next tick. If this repeats, check that the reply consumers and the web server in this container are running and not stopped; /readyz names the one that stopped reporting.',
                ['path' => $this->settings->internalDatabasePath, 'seconds' => PulseSettings::INTERNAL_DATABASE_BUSY_TIMEOUT_SECONDS, 'exception' => $exception]
            );

            return;
        }

        $output->writeln('Pulse requested snapshots from the services that were not waiting for one.', OutputInterface::VERBOSITY_VERBOSE);
    }

    public function getSubscribedSignals(): array
    {
        return [SIGTERM, SIGINT];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->stopped = true;

        return false;
    }

    private function waitUntil(DateTimeImmutable $tickAt): bool
    {
        while (! $this->stopped && DateTimeImmutable::createFromInterface($this->clock->now()) < $tickAt) {
            $this->clock->sleep(1);
        }

        return ! $this->stopped;
    }
}
