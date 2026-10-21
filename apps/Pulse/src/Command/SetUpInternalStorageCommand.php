<?php

declare(strict_types=1);

namespace App\Command;

use App\Settings\PulseSettings;
use App\Storage\InternalDatabase;
use App\Storage\InternalTables;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'pulse:setup', description: 'Creates the internal SQLite database file and the tables Pulse keeps its own state in')]
final class SetUpInternalStorageCommand extends Command
{
    public function __construct(
        private readonly InternalDatabase $database,
        private readonly PulseSettings $settings,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $directory = dirname($this->settings->internalDatabasePath);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            $output->writeln(sprintf('<error>Pulse could not create the directory "%s" for its internal database. Make it writable by the user Pulse runs as.</error>', $directory));

            return Command::FAILURE;
        }

        $connection = $this->database->connection();
        $journalMode = $connection->fetchOne('PRAGMA journal_mode=WAL');
        foreach (InternalTables::createStatements() as $statement) {
            $connection->executeStatement($statement);
        }
        $busyTimeout = $connection->fetchOne('PRAGMA busy_timeout');
        $output->writeln(sprintf('Pulse internal database ready at %s (journal_mode=%s, busy_timeout=%s)', $this->settings->internalDatabasePath, $journalMode, $busyTimeout));

        return Command::SUCCESS;
    }
}
