<?php

declare(strict_types=1);

namespace Test\Ecotone\JMSConverter\Integration;

use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Gateway\SerializerGateway;
use Ecotone\Api\JMSConverter\JMSConverterConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * licence Apache-2.0
 * @internal
 */
final class MetadataCacheFreshnessTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ecotone_jms_metadata_freshness_' . bin2hex(random_bytes(6));
        mkdir($this->directory, recursive: true);
    }

    protected function tearDown(): void
    {
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->directory);
    }

    #[TestWith(['dev'])]
    #[TestWith(['test'])]
    public function test_a_class_that_gained_a_property_deserializes_with_it_outside_production(string $environment): void
    {
        $this->assertSame(
            ['seat' => 'A1', 'row' => 7],
            $this->deserializeAfterTheClassGainedARow($environment, []),
        );
    }

    #[TestWith(['prod'])]
    #[TestWith(['production'])]
    public function test_production_keeps_the_cached_metadata_of_a_class_that_gained_a_property(string $environment): void
    {
        $this->assertSame(
            ['seat' => 'A1'],
            $this->deserializeAfterTheClassGainedARow($environment, []),
        );
    }

    public function test_the_metadata_cache_freshness_check_can_be_enabled_in_production(): void
    {
        $this->assertSame(
            ['seat' => 'A1', 'row' => 7],
            $this->deserializeAfterTheClassGainedARow('prod', [JMSConverterConfiguration::createWithDefaults()->withMetadataCacheFreshnessCheck(true)]),
        );
    }

    public function test_the_metadata_cache_freshness_check_can_be_disabled_outside_production(): void
    {
        $this->assertSame(
            ['seat' => 'A1'],
            $this->deserializeAfterTheClassGainedARow('dev', [JMSConverterConfiguration::createWithDefaults()->withMetadataCacheFreshnessCheck(false)]),
        );
    }

    /**
     * @param object[] $extensionObjects
     * @return array<string, mixed>
     */
    private function deserializeAfterTheClassGainedARow(string $environment, array $extensionObjects): array
    {
        $namespace = 'Test\\Ecotone\\JMSConverter\\Generated\\Run' . bin2hex(random_bytes(6));
        $className = $namespace . '\\SeatHeld';
        $classFile = $this->directory . '/SeatHeld.php';
        $registeredClassName = $namespace . '\\SeatMap';
        $registeredClassFile = $this->directory . '/SeatMap.php';
        $cacheDirectory = $this->directory . '/cache';

        file_put_contents($registeredClassFile, "<?php\nnamespace {$namespace};\nfinal class SeatMap\n{\n}\n");
        file_put_contents($classFile, "<?php\nnamespace {$namespace};\nfinal class SeatHeld\n{\n    public string \$seat;\n}\n");
        $previousDeployment = $this->directory . '/previous_deployment.php';
        file_put_contents($previousDeployment, sprintf(
            <<<'PHP'
                <?php
                require %s;
                require %s;
                require %s;
                \Ecotone\Api\Lite\EcotoneLite::bootstrap(
                    [%s],
                    extensionObjects: unserialize(%s),
                    enabledModules: [\Ecotone\Api\ExtensionObject\ModulePackageList::JMS_CONVERTER_PACKAGE],
                    useCachedVersion: true,
                    environment: %s,
                    cacheDirectoryPath: %s,
                )->getGatewayByName(\Ecotone\Api\Gateway\SerializerGateway::class)->convertToPHP('{"seat":"A1"}', 'application/json', %s);
                PHP,
            var_export($this->composerAutoloader(), true),
            var_export($registeredClassFile, true),
            var_export($classFile, true),
            var_export($registeredClassName, true),
            var_export(serialize($extensionObjects), true),
            var_export($environment, true),
            var_export($cacheDirectory, true),
            var_export($className, true),
        ));
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($previousDeployment) . ' 2>&1', $output, $exitCode);
        $this->assertSame([0, []], [$exitCode, $output]);

        file_put_contents($classFile, "<?php\nnamespace {$namespace};\nfinal class SeatHeld\n{\n    public string \$seat;\n    public int \$row;\n}\n");
        touch($classFile, time() + 10);
        require $registeredClassFile;
        require $classFile;

        $seatHeld = EcotoneLite::bootstrap(
            [$registeredClassName],
            extensionObjects: $extensionObjects,
            enabledModules: [ModulePackageList::JMS_CONVERTER_PACKAGE],
            useCachedVersion: true,
            environment: $environment,
            cacheDirectoryPath: $cacheDirectory,
        )->getGatewayByName(SerializerGateway::class)->convertToPHP('{"seat":"A1","row":7}', MediaType::APPLICATION_JSON, $className);

        return get_object_vars($seatHeld);
    }

    private function composerAutoloader(): string
    {
        foreach (get_included_files() as $includedFile) {
            if (str_ends_with($includedFile, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php')) {
                return $includedFile;
            }
        }

        $this->fail('The test runs without a Composer autoloader');
    }
}
