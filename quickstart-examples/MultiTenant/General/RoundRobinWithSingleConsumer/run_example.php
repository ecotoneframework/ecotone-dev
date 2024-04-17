<?php

use App\MultiTenant\ProcessImage;
use Ecotone\Lite\EcotoneLiteApplication;
use Ecotone\Messaging\Handler\Logger\EchoLogger;
use Enqueue\AmqpExt\AmqpConnectionFactory;
use PHPUnit\Framework\TestCase;

require __DIR__ . "/vendor/autoload.php";
$messagingSystem = EcotoneLiteApplication::bootstrap(
    [Enqueue\AmqpExt\AmqpConnectionFactory::class => new AmqpConnectionFactory(['dsn' => getenv('RABBIT_HOST') ? getenv('RABBIT_HOST') : "amqp://guest:guest@localhost:5672/%2f"]), 'logger' => new EchoLogger()],
    pathToRootCatalog: __DIR__
);
$commandBus = $messagingSystem->getCommandBus();
$queryBus = $messagingSystem->getQueryBus();

echo "Running demo:\n";
TestCase::assertSame([], $queryBus->sendWithRouting('getProcessedImages'));

$commandBus->send(new ProcessImage('1', "Picture of Milk"));
$messagingSystem->run('image_processing');
TestCase::assertSame(['1'], $queryBus->sendWithRouting('getProcessedImages'));

$commandBus->send(new ProcessImage('2', 'Picture of Chocolate'));
$messagingSystem->run('image_processing');
TestCase::assertSame(['1', '2'], $queryBus->sendWithRouting('getProcessedImages'));

$commandBus->send(new ProcessImage('3', 'Picture of town'));
$messagingSystem->run('image_processing');
TestCase::assertSame(['1', '2', '3'], $queryBus->sendWithRouting('getProcessedImages'));