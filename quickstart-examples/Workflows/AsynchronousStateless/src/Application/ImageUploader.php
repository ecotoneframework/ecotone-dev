<?php

declare(strict_types=1);

namespace App\Workflow\Application;

final readonly class ImageUploader
{
    public function uploadImage(string $filePath): void
    {
        // In real example we could upload to S3
        $pathInfo = pathinfo($filePath);
        rename($filePath,  $pathInfo['dirname'] . '/S3Storage/' . $pathInfo['filename'] . '.' . $pathInfo['extension']);

        echo "Image uploaded.\n";
    }
}