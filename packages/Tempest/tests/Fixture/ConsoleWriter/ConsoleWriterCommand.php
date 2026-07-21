<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\ConsoleWriter;

use Ecotone\Messaging\Attribute\ConsoleCommand;
use Ecotone\Messaging\Console\ConsoleWriter;

/**
 * licence Apache-2.0
 */
final class ConsoleWriterCommand
{
    #[ConsoleCommand('console-writer:show', 'Shows formatted console writer output')]
    public function execute(string $name, ConsoleWriter $writer): void
    {
        $writer->info('Starting ' . $name);
        $writer->success('Processed ' . $name);
        $writer->warning('Almost done ' . $name);
        $writer->error('Failed ' . $name);
        $writer->table(['Name', 'Status'], [[$name, 'running']]);

        $progressBar = $writer->progressBar(2);
        $progressBar->advance();
        $progressBar->advance();
        $progressBar->finish();
    }
}
