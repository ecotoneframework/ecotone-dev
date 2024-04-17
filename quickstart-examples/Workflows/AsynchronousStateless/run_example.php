<?php

use App\Workflow\Application\ProcessImage;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . "/../boostrap.php";

$messagingSystem = Workflows\bootstrapEcotone(__DIR__);
$commandBus = $messagingSystem->getCommandBus();
$queryBus = $messagingSystem->getQueryBus();

echo "Running demo:\n";

$commandBus->send(new ProcessImage(__DIR__ . '/../ecotone_logo.png'));
echo "Running asynchronous consumer\n";
$messagingSystem->run('async_workflow', ExecutionPollingMetadata::createWithTestingSetup());

echo "Demo finished.\n";