<?php

declare(strict_types=1);

/*
 * licence Apache-2.0
 *
 * php breakdown.php [messages]
 * Shows what the time of one consumed message is made of, by consuming the same messages in one process with one more
 * part switched on at a time: the broker alone, Ecotone's consumer, the database transaction with deduplication,
 * the handler's insert, and the 20 ms the handler waits for a downstream service.
 */

use Doctrine\DBAL\Connection;
use Ecotone\Api\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Api\Amqp\AmqpExtConnectionFactory;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Dbal\Attribute\CurrentConnection;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DatabaseSetupManager;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Lite\EcotoneLite;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

final class HandlerDoingNothing
{
    public float $secondsInHandler = 0.0;

    #[Asynchronous('breakdown_orders')]
    #[CommandHandler('breakdown.place', endpointId: 'breakdown.place.endpoint')]
    public function place(array $order): void
    {
    }
}

final class HandlerInserting
{
    public float $secondsInHandler = 0.0;

    #[Asynchronous('breakdown_orders')]
    #[CommandHandler('breakdown.place', endpointId: 'breakdown.place.endpoint')]
    public function place(array $order, #[CurrentConnection] Connection $connection): void
    {
        $startedAt = hrtime(true);
        $connection->insert('breakdown_orders', ['order_id' => $order['orderId'], 'product' => $order['product']]);
        $this->secondsInHandler += (hrtime(true) - $startedAt) / 1e9;
    }
}

final class HandlerInsertingAndWaiting
{
    public float $secondsInHandler = 0.0;

    #[Asynchronous('breakdown_orders')]
    #[CommandHandler('breakdown.place', endpointId: 'breakdown.place.endpoint')]
    public function place(array $order, #[CurrentConnection] Connection $connection): void
    {
        $startedAt = hrtime(true);
        $connection->insert('breakdown_orders', ['order_id' => $order['orderId'], 'product' => $order['product']]);
        usleep(20_000);
        $this->secondsInHandler += (hrtime(true) - $startedAt) / 1e9;
    }
}

const VARIANTS = [
    'broker only: get and acknowledge, no Ecotone' => [null, false],
    'Ecotone consumer, handler does nothing' => [HandlerDoingNothing::class, false],
    '+ database transaction and deduplication' => [HandlerDoingNothing::class, true],
    '+ handler inserts one row' => [HandlerInserting::class, true],
    '+ handler waits 20 ms' => [HandlerInsertingAndWaiting::class, true],
];

$messages = (int) ($argv[1] ?? 2000);
if (! isset($argv[2])) {
    printf("%-46s | %14s | %18s | %20s | %22s\n", 'what is switched on', 'ms per message', 'CPU ms per message', 'ms inside the handler', 'statements per message');
    foreach (array_keys(VARIANTS) as $index => $variant) {
        $result = json_decode((string) shell_exec('php ' . escapeshellarg(__FILE__) . " {$messages} {$index}"), true);
        printf("%-46s | %14.3f | %18.3f | %20.3f | %22.1f\n", $variant, $result['wall'], $result['processor'], $result['handler'], $result['statements']);
    }
    exit(0);
}

[$handlerClass, $withDatabase] = array_values(VARIANTS)[(int) $argv[2]];
$brokerFactory = new AmqpExtConnectionFactory(['dsn' => getenv('RABBIT_HOST')]);
$databaseFactory = new DbalConnectionFactory(['dsn' => getenv('DATABASE_MYSQL')]);
$administrator = (new DbalConnectionFactory(['dsn' => getenv('DATABASE_MYSQL')]))->createContext()->getDbalConnection();
$statementsHandled = static fn (): int => (int) $administrator->fetchNumeric("SHOW GLOBAL STATUS LIKE 'Questions'")[1];
$processorSeconds = static function (): float {
    $usage = getrusage();

    return $usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec'] + ($usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec']) / 1_000_000;
};

$handler = new ($handlerClass ?? HandlerDoingNothing::class)();
$services = [$handler, AmqpExtConnectionFactory::class => $brokerFactory];
$extensionObjects = [AmqpBackedMessageChannelBuilder::create('breakdown_orders')];
$modules = [ModulePackageList::AMQP_PACKAGE];
if ($withDatabase) {
    $services[DbalConnectionFactory::class] = $databaseFactory;
    $extensionObjects[] = DbalConfiguration::createWithDefaults();
    $modules[] = ModulePackageList::DBAL_PACKAGE;
}
$ecotone = EcotoneLite::bootstrap(
    classesToResolve: [$handler::class],
    containerOrAvailableServices: $services,
    extensionObjects: $extensionObjects,
    enabledModules: $modules,
    pathToRootCatalog: __DIR__ . '/var/breakdown-' . $argv[2],
    environment: 'prod',
);

$broker = $brokerFactory->createContext();
$queue = $broker->createQueue('breakdown_orders');
try {
    $broker->purgeQueue($queue);
} catch (Throwable) {
}
if ($withDatabase) {
    $ecotone->getServiceFromContainer(DatabaseSetupManager::class)->initializeAll();
}
$administrator->executeStatement('DROP TABLE IF EXISTS breakdown_orders');
$administrator->executeStatement('CREATE TABLE breakdown_orders (order_id INTEGER PRIMARY KEY, product VARCHAR(255))');
for ($orderId = 1; $orderId <= $messages; $orderId++) {
    $ecotone->getCommandBus()->sendWithRouting('breakdown.place', ['orderId' => $orderId, 'product' => 'milk']);
}

$statementsBefore = $statementsHandled();
$processorBefore = $processorSeconds();
$startedAt = hrtime(true);
if ($handlerClass === null) {
    $consumer = $broker->createConsumer($queue);
    while ($message = $consumer->receiveNoWait()) {
        $consumer->acknowledge($message);
    }
} else {
    $samples = [];
    if (getenv('BREAKDOWN_SAMPLES')) {
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, static function () use (&$samples): void {
            $frames = [];
            foreach (array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 1, 4) as $frame) {
                $frames[] = (isset($frame['class']) ? substr(strrchr('\\' . $frame['class'], '\\'), 1) . '::' : '') . $frame['function'];
            }
            $samples[implode(' < ', $frames)] = ($samples[implode(' < ', $frames)] ?? 0) + 1;
        });
        $sampled = getmypid();
        $sampler = pcntl_fork();
        if ($sampler === 0) {
            while (posix_kill($sampled, SIGALRM)) {
                usleep(700);
            }
            exit(0);
        }
    }
    $ecotone->run('breakdown_orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
    if ($samples !== []) {
        posix_kill($sampler, SIGKILL);
        pcntl_signal(SIGALRM, SIG_IGN);
        arsort($samples);
        foreach (array_slice($samples, 0, 14, true) as $where => $count) {
            fwrite(STDERR, sprintf("%5.1f%%  %s\n", $count / array_sum($samples) * 100, $where));
        }
    }
}
$seconds = (hrtime(true) - $startedAt) / 1e9;

echo json_encode([
    'wall' => $seconds / $messages * 1000,
    'processor' => ($processorSeconds() - $processorBefore) / $messages * 1000,
    'handler' => $handler->secondsInHandler / $messages * 1000,
    'statements' => ($statementsHandled() - $statementsBefore - 1) / $messages,
]);
