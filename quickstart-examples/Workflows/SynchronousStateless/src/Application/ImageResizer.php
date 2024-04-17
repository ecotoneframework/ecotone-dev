<?php

declare(strict_types=1);

namespace App\Workflow\Application;

use Intervention\Image\ImageManager;

final readonly class ImageResizer
{
    public function __construct(private ImageManager $imageManager) {}

    /**
     * @return string Resized image path
     */
    public function resizeImage(string $filePath): string
    {
        $pathInfo = pathinfo($filePath);

        $image = $this->imageManager->read($filePath);
        $resizedImagePath = $pathInfo['dirname'] . DIRECTORY_SEPARATOR . $pathInfo['filename'] . '_resized.' . $pathInfo['extension'];
        $image->resize(220, 140)->save($resizedImagePath);

        echo "Image resized.\n";

        return $resizedImagePath;
    }
}