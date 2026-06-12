<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Config\Container\CodeGeneration;

use Ecotone\Messaging\Config\Container\CodeGeneration\GeneratedClassWriter;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class GeneratedClassWriterTest extends TestCase
{
    public function test_it_writes_class_under_content_addressed_name(): void
    {
        $outputDir = $this->uniqueOutputDirectory();

        $generatedClass = (new GeneratedClassWriter())->write(
            'AGeneratedService',
            $outputDir,
            fn (string $className) => "<?php final class {$className} { public function greet(): string { return 'hello'; } }",
        );

        self::assertStringStartsWith('AGeneratedService_', $generatedClass->className);
        self::assertSame($outputDir . DIRECTORY_SEPARATOR . $generatedClass->className . '.php', $generatedClass->filePath);
        require $generatedClass->filePath;
        self::assertSame('hello', (new $generatedClass->className())->greet());
    }

    public function test_it_resolves_same_class_name_for_same_content(): void
    {
        $outputDir = $this->uniqueOutputDirectory();
        $buildCode = fn (string $className) => "<?php final class {$className} {}";

        $first = (new GeneratedClassWriter())->write('AGeneratedService', $outputDir, $buildCode);
        $second = (new GeneratedClassWriter())->write('AGeneratedService', $outputDir, $buildCode);

        self::assertSame($first->className, $second->className);
        self::assertSame($first->filePath, $second->filePath);
    }

    public function test_it_resolves_different_class_names_for_different_content(): void
    {
        $outputDir = $this->uniqueOutputDirectory();

        $first = (new GeneratedClassWriter())->write('AGeneratedService', $outputDir, fn (string $className) => "<?php final class {$className} { public int \$version = 1; }");
        $second = (new GeneratedClassWriter())->write('AGeneratedService', $outputDir, fn (string $className) => "<?php final class {$className} { public int \$version = 2; }");

        self::assertNotSame($first->className, $second->className);
        self::assertFileExists($first->filePath);
        self::assertFileExists($second->filePath);
    }

    public function test_it_creates_missing_output_directory(): void
    {
        $outputDir = $this->uniqueOutputDirectory() . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'handlers';

        $generatedClass = (new GeneratedClassWriter())->write(
            'AGeneratedService',
            $outputDir,
            fn (string $className) => "<?php final class {$className} {}",
        );

        self::assertFileExists($generatedClass->filePath);
    }

    private function uniqueOutputDirectory(): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ecotone_generated_class_writer_test' . DIRECTORY_SEPARATOR . uniqid('', true);
        mkdir($directory, 0777, true);

        return $directory;
    }
}
