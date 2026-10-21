<?php

declare(strict_types=1);

namespace App\Command;

use App\Settings\PulseSettings;
use App\Snapshot\Snapshots;
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
        private readonly PulseSettings $settings,
        #[Autowire(service: 'pulse.clock')] private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('once', null, InputOption::VALUE_NONE, 'Run a single refresh tick and exit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        do {
            $this->snapshots->refreshIdleServices();
            $output->writeln('Pulse requested snapshots from the services that were not waiting for one.', OutputInterface::VERBOSITY_VERBOSE);
        } while (! $input->getOption('once') && $this->waitForNextTick());

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

    private function waitForNextTick(): bool
    {
        for ($second = 0; $second < $this->settings->refreshIntervalSeconds && ! $this->stopped; $second++) {
            $this->clock->sleep(1);
        }

        return ! $this->stopped;
    }
}
