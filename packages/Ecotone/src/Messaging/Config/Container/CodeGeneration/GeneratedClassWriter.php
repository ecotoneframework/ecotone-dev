<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config\Container\CodeGeneration;

use Ecotone\Messaging\Support\InvalidArgumentException;

/**
 * licence Apache-2.0
 */
final class GeneratedClassWriter
{
    private const CLASS_NAME_PLACEHOLDER = '__ECOTONE_GENERATED_CLASS_NAME__';

    /**
     * @param callable(string $classNamePlaceholder): string $buildCode
     */
    public function write(string $baseName, string $outputDirectory, callable $buildCode): GeneratedClass
    {
        $code = $buildCode(self::CLASS_NAME_PLACEHOLDER);
        $className = $baseName . '_' . substr(sha1($code), 0, 8);
        $code = str_replace(self::CLASS_NAME_PLACEHOLDER, $className, $code);
        $filePath = rtrim($outputDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $className . '.php';

        if (! is_file($filePath)) {
            $this->writeAtomically($filePath, $code);
        }

        return new GeneratedClass($className, $filePath);
    }

    private function writeAtomically(string $filePath, string $code): void
    {
        $directory = dirname($filePath);
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $temporaryFilePath = tempnam($directory, basename($filePath));
        if ($temporaryFilePath === false || file_put_contents($temporaryFilePath, $code) === false) {
            throw InvalidArgumentException::create("Cannot write generated class to {$filePath}");
        }
        chmod($temporaryFilePath, 0644);
        rename($temporaryFilePath, $filePath);
    }
}
