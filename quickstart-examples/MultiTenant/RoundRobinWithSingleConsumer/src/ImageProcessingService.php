<?php

namespace App\MultiTenant;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;

final class ImageProcessingService
{
    /** @var string[]  */
    private array $processedImages = [];

    #[Asynchronous("image_processing")]
    #[CommandHandler(endpointId:"processImage")]
    public function notifyAboutNewOrder(ProcessImage $command) : void
    {
        // process image

        $this->processedImages[] = $command->imageId;
    }

    #[QueryHandler("getProcessedImages")]
    public function getProcessedImages()
    {
        return $this->processedImages;
    }
}