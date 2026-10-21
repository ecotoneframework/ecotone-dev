<?php

declare(strict_types=1);

namespace App\Command;

use App\Health\Heartbeats;
use App\Settings\PulseSettings;
use App\Snapshot\Snapshots;
use DateTimeImmutable;
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
            $this->snapshots->refreshIdleServices();
            $this->heartbeats->record(Heartbeats::REFRESH);
            $this->heartbeats->recordRunningConsumers((string) (getenv('PULSE_RUN_DIR') ?: '/tmp/pulse-run'));
            $done++;
            $output->writeln('Pulse requested snapshots from the services that were not waiting for one.', OutputInterface::VERBOSITY_VERBOSE);
            $nextTickAt = $nextTickAt->modify(sprintf('+%d seconds', $this->settings->refreshIntervalSeconds));
        } while (($ticks === 0 || $done < $ticks) && $this->waitUntil($nextTickAt));

        return Command::SUCCESS;
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
