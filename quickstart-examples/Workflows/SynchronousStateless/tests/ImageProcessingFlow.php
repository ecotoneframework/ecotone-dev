<?php

declare(strict_types=1);

namespace Tests\App\Workflow;

use App\Workflow\Application\ImageProcessingWorkflow;
use App\Workflow\Application\ImageResizer;
use App\Workflow\Application\ImageUploader;
use App\Workflow\Application\ProcessImage;
use Ecotone\Lite\EcotoneLite;
use PHPUnit\Framework\TestCase;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

final class ImageProcessingFlow extends TestCase
{
    public function test_workflow_is_correctly_run()
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            /** Testing only the Message Handlers registered in given classes */
            [ImageProcessingWorkflow::class],
            /** We could provide some Stub implementations */
            [
                ImageProcessingWorkflow::class => new ImageProcessingWorkflow(),
                ImageResizer::class => new ImageResizer(new ImageManager(new Driver())),
                ImageUploader::class => new ImageUploader()
            ]
        );

        $ecotoneLite->sendCommand(new ProcessImage(__DIR__ . '/../../ecotone_logo.png'));

        $this->assertFileExists(__DIR__ . '/../../S3Storage/ecotone_logo_resized.png');
    }
}