<?php

declare(strict_types=1);

namespace App\Command;

use App\Doctor\Doctor;
use App\Doctor\DoctorCheck;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'pulse:doctor', description: 'Checks the settings, every connection and every service channel, and says what to do about each failure')]
final class DoctorCommand extends Command
{
    public function __construct(private readonly Doctor $doctor)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'text or json', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $checks = $this->doctor->diagnose();
        $failed = array_filter($checks, static fn (DoctorCheck $check) => $check->failed());

        if ($input->getOption('format') === 'json') {
            $output->writeln(json_encode(['ok' => $failed === [], 'checks' => array_map(static fn (DoctorCheck $check) => $check->toArray(), $checks)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return $failed === [] ? Command::SUCCESS : Command::FAILURE;
        }

        $output->writeln('Pulse doctor');
        foreach ($checks as $check) {
            $output->writeln(sprintf('  [%s] %s: %s', ['ok' => ' ok ', 'info' => 'info', 'fail' => 'FAIL'][$check->status], $check->name, $check->message), OutputInterface::OUTPUT_RAW);
            if ($check->fix !== null) {
                $output->writeln('         -> ' . $check->fix, OutputInterface::OUTPUT_RAW);
            }
        }
        $output->writeln($failed === [] ? "\nAll checks passed." : "\nSome checks failed.");

        return $failed === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
