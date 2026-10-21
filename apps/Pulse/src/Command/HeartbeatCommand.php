<?php

declare(strict_types=1);

namespace App\Command;

use App\Health\Heartbeats;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'pulse:heartbeat', description: 'Records that the named processes (for example consumer:ecotone_pulse) are alive, for GET /readyz')]
final class HeartbeatCommand extends Command
{
    public function __construct(private readonly Heartbeats $heartbeats)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('processes', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Process names, for example consumer:ecotone_pulse');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($input->getArgument('processes') as $process) {
            $this->heartbeats->record($process);
        }

        return Command::SUCCESS;
    }
}
