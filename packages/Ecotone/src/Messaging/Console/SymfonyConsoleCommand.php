<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Console;

use Ecotone\Api\Console\ConsoleCommandResultSet;
use Ecotone\Messaging\Config\ConsoleCommandConfiguration;
use Ecotone\Messaging\Gateway\ConsoleCommandRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * licence Apache-2.0
 */
final class SymfonyConsoleCommand extends Command
{
    public function __construct(private ConsoleCommandConfiguration $consoleCommand, private ConsoleCommandRunner $consoleCommandRunner, private DelegatingConsoleWriter $consoleWriter)
    {
        parent::__construct($consoleCommand->getName());
    }

    protected function configure(): void
    {
        $this->setDescription($this->consoleCommand->getDescription());

        foreach ($this->consoleCommand->getParameters() as $parameter) {
            if ($parameter->isOption()) {
                $this->addOption(
                    $parameter->getName(),
                    null,
                    $parameter->isArray() ? InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY : InputOption::VALUE_OPTIONAL,
                    '',
                    $parameter->getDefaultValue(),
                );
            } elseif ($parameter->hasDefaultValue()) {
                $this->addArgument($parameter->getName(), InputArgument::OPTIONAL, '', $parameter->getDefaultValue());
            } else {
                $this->addArgument($parameter->getName(), InputArgument::REQUIRED);
            }
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $writer = new SymfonyConsoleWriter($output);
        $result = $this->consoleWriter->executeWith(
            $writer,
            fn () => $this->consoleCommandRunner->execute($this->consoleCommand->getName(), array_merge($input->getArguments(), $input->getOptions())),
        );

        if ($result instanceof ConsoleCommandResultSet) {
            $writer->table($result->columnHeaders, $result->rows);
        }

        return self::SUCCESS;
    }
}
