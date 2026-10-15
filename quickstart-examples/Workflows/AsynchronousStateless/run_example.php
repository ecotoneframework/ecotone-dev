<?php

use App\Workflow\Application\ImageProcessingWorkflow;
use App\Workflow\Application\ImageResizer;
use App\Workflow\Application\ImageUploader;
use App\Workflow\Application\ProcessImage;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . "/../boostrap.php";

$messagingSystem = Workflows\bootstrapEcotone(__DIR__, [
    new ImageProcessingWorkflow(),
    new ImageResizer(new ImageManager(new Driver())),
    new ImageUploader(),
]);
$commandBus = $messagingSystem->getCommandBus();
$queryBus = $messagingSystem->getQueryBus();

echo "Running demo:\n";

$commandBus->send(new ProcessImage(__DIR__ . '/../ecotone_logo.png'));
echo "Running asynchronous consumer\n";
$messagingSystem->run('async_workflow', ExecutionPollingMetadata::createWithTestingSetup());

echo "Demo finished.\n";