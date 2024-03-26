<?php

use App\Workflow\Application\ProcessImage;

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . "/../boostrap.php";

$messagingSystem = Workflows\bootstrapEcotone(__DIR__);
$commandBus = $messagingSystem->getCommandBus();
$queryBus = $messagingSystem->getQueryBus();

echo "Running demo:\n";

$commandBus->send(new ProcessImage(__DIR__ . '/../ecotone_logo.png'));

echo "Demo finished.\n";