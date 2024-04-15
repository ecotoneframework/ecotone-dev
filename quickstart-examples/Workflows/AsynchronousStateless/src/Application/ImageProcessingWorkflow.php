<?php

declare(strict_types=1);

namespace App\Workflow\Application;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\InternalHandler;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Modelling\Attribute\CommandHandler;

final readonly class ImageProcessingWorkflow
{
    #[CommandHandler(outputChannelName: 'image.resize')]
    public function validateImage(ProcessImage $command): ProcessImage
    {
        $this->validateExtension($command->path);

        return $command;
    }

    #[Asynchronous('async_workflow')]
    #[InternalHandler(inputChannelName: 'image.resize', outputChannelName: 'image.upload', endpointId: 'imageResizeEndpoint')]
    public function resizeImage(ProcessImage $command, ImageResizer $imageResizer): ProcessImage
    {
        $resizedImagePath = $imageResizer->resizeImage($command->path);

        return new ProcessImage($resizedImagePath);
    }

    #[Asynchronous('async_workflow')]
    #[InternalHandler(inputChannelName: 'image.upload', endpointId: 'imageUploadEndpoint')]
    public function uploadImage(ProcessImage $command, ImageUploader $imageUploader): void
    {
        $imageUploader->uploadImage($command->path);
    }

    private function validateExtension(string $path): void
    {
        Assert::isTrue(
            in_array(pathinfo($path)['extension'], ['jpg', 'jpeg', 'png', 'gif']),
            "Unsupported file format"
        );
    }
}