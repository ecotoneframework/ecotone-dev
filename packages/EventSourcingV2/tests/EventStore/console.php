<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

use Ecotone\EventSourcingV2\EventStore\Event;
use Ecotone\EventSourcingV2\EventStore\SQL\MysqlEventStore;
use Ecotone\EventSourcingV2\EventStore\SQL\PostgresEventStore;
use Ecotone\EventSourcingV2\EventStore\StreamEventId;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\EventSourcingV2\EventStore\Fixtures\PostgresTableProjector;
use Test\Ecotone\EventSourcingV2\EventStore\SQL\Helpers\DatabaseConfig;

require_once dirname(__DIR__) . '/../vendor/autoload.php';

$application = new Application();

function createEventStore(string $configString): PostgresEventStore|MysqlEventStore
{
    $config = DatabaseConfig::fromString($configString);

    $connection = $config->getConnection();

    return $config->createEventStore(
        projectors: [
            'base' => new PostgresTableProjector($connection, 'test_event_base'),
            'catchup' => new PostgresTableProjector($connection, 'test_event_catchup'),
        ],
        connection: $connection,
    );
}

$application->register('long-running-append')
    ->addOption('dbConfig', null, InputOption::VALUE_REQUIRED, 'Database configuration serialized')
    ->addOption('streamId', null, InputOption::VALUE_OPTIONAL, 'Stream ID')
    ->addOption('start_event_count', null, InputOption::VALUE_OPTIONAL, 'Number of events appended at start', 1)
    ->addOption('event_count', null, InputOption::VALUE_OPTIONAL, 'Number of events to append', 1)
    ->setCode(function (InputInterface $input, OutputInterface $output) {
        $eventStore = createEventStore($input->getOption('dbConfig'));
        $connection = $eventStore->connection();

        $streamId = new StreamEventId($input->getOption('streamId') ?: Uuid::uuid4());
        $startEventCount = (int) $input->getOption('start_event_count');
        $eventCount = (int) $input->getOption('event_count');

        $eventStore->append($streamId, array_map(fn () => new Event('start_event', ['data' => 'value']), range(1, $startEventCount)));

        $transaction = $connection->beginTransaction();

        try {
            $eventStore->append($streamId, array_map(fn () => new Event('long_running_event', ['data' => 'value']), range(1, $eventCount)));

            $output->writeln('Events appended, waiting some input to commit');

            $questionHelper = new QuestionHelper();
            $question = new Question('Press enter to commit');

            $questionHelper->ask($input, $output, $question);

            $transaction->commit();
            $output->writeln('Events committed');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('Error occurred, rolling back');
            $transaction->rollBack();
            throw $e;
        }
    });

$application->register("catchup-projection")
    ->addOption('dbConfig', null, InputOption::VALUE_REQUIRED, 'Database configuration serialized')
    ->addOption('streamId', null, InputOption::VALUE_OPTIONAL, 'Stream ID')
    ->setCode(function (InputInterface $input, OutputInterface $output) {
        $eventStore = createEventStore($input->getOption('dbConfig'));

        $output->writeln('Running catchup projection');

        try {
            $eventStore->catchupProjection('catchup');
        } catch (\Throwable $e) {
            $output->writeln(sprintf('Error occurred: %s', $e->getMessage()));
            throw $e;
        }

        $output->writeln('Catchup projection done');
        return Command::SUCCESS;
    });

$application->register("init")
    ->addOption('dbConfig', null, InputOption::VALUE_REQUIRED, 'Database configuration serialized')
    ->addOption('streamId', null, InputOption::VALUE_OPTIONAL, 'Stream ID')
    ->setCode(function (InputInterface $input, OutputInterface $output) {
        $eventStore = createEventStore($input->getOption('dbConfig'));

        $eventStore->removeProjection('base');
        $eventStore->removeProjection('catchup');

        $eventStore->addProjection('base');
        $eventStore->catchupProjection('base');

        $eventStore->addProjection('catchup');

        $output->writeln('Projections initialized');
        return Command::SUCCESS;
    });

$application->register("create-schema")
    ->addOption('dbConfig', null, InputOption::VALUE_REQUIRED, 'Database configuration serialized')
    ->setCode(function (InputInterface $input, OutputInterface $output) {
        $eventStore = createEventStore($input->getOption('dbConfig'));

        $eventStore->schemaUp();
        return Command::SUCCESS;
    });

$application->register("drop-schema")
    ->addOption('dbConfig', null, InputOption::VALUE_REQUIRED, 'Database configuration serialized')
    ->setCode(function (InputInterface $input, OutputInterface $output) {
        $eventStore = createEventStore($input->getOption('dbConfig'));

        $eventStore->schemaDown();
        return Command::SUCCESS;
    });


$application->run();