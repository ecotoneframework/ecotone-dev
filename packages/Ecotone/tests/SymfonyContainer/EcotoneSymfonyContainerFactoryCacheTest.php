<?php

declare(strict_types=1);

namespace Test\Ecotone\SymfonyContainer;

use Ecotone\Lite\InMemoryPSRContainer;
use Ecotone\Messaging\Config\Container\Compiler\CompilerPass;
use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Ecotone\Messaging\ConfigurationVariableService;
use Ecotone\Messaging\InMemoryConfigurationVariableService;
use Ecotone\SymfonyContainer\EcotoneSymfonyContainerFactory;
use Ecotone\Test\StubLogger;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
class EcotoneSymfonyContainerFactoryCacheTest extends TestCase
{
    public function test_it_loads_dumped_container_from_cache(): void
    {
        $cacheConfiguration = new ServiceCacheConfiguration($this->uniqueCacheDirectory(), true);
        $builder = new ContainerBuilder();
        $builder->replace('aService', new Definition(ACachedService::class, ['someName']));
        EcotoneSymfonyContainerFactory::build($builder, $cacheConfiguration);

        $loaded = EcotoneSymfonyContainerFactory::loadCached($cacheConfiguration);

        self::assertNotNull($loaded);
        self::assertEquals(new ACachedService('someName'), $loaded->get('aService'));
    }

    public function test_it_rebuilds_fresh_container_when_cache_files_are_removed_in_same_process(): void
    {
        $cacheConfiguration = new ServiceCacheConfiguration($this->uniqueCacheDirectory(), true);
        $builder = new ContainerBuilder();
        $builder->replace('aService', new Definition(ACachedService::class, ['first']));
        EcotoneSymfonyContainerFactory::build($builder, $cacheConfiguration);

        foreach (glob($cacheConfiguration->getPath() . '/*') as $file) {
            unlink($file);
        }

        self::assertNull(EcotoneSymfonyContainerFactory::loadCached($cacheConfiguration));

        $rebuiltBuilder = new ContainerBuilder();
        $rebuiltBuilder->replace('aService', new Definition(ACachedService::class, ['second']));
        $rebuilt = EcotoneSymfonyContainerFactory::build($rebuiltBuilder, $cacheConfiguration);

        self::assertSame('second', $rebuilt->get('aService')->name);
    }

    public function test_it_exposes_config_hash_on_built_and_cache_loaded_container(): void
    {
        $cacheConfiguration = new ServiceCacheConfiguration($this->uniqueCacheDirectory(), true);
        $builder = new ContainerBuilder();
        $builder->replace('aService', new Definition(ACachedService::class, ['someName']));

        $built = EcotoneSymfonyContainerFactory::build($builder, $cacheConfiguration, configHash: 'abc123');
        self::assertSame('abc123', $built->getConfigHash());

        $loaded = EcotoneSymfonyContainerFactory::loadCached($cacheConfiguration);
        self::assertSame('abc123', $loaded->getConfigHash());
    }

    public function test_it_returns_null_when_no_dumped_container_exists(): void
    {
        $cacheConfiguration = new ServiceCacheConfiguration($this->uniqueCacheDirectory(), true);

        self::assertNull(EcotoneSymfonyContainerFactory::loadCached($cacheConfiguration));
    }

    public function test_it_resolves_external_references_in_cache_loaded_container(): void
    {
        $cacheConfiguration = new ServiceCacheConfiguration($this->uniqueCacheDirectory(), true);
        $builder = new ContainerBuilder();
        $builder->replace('aService', new Definition(ACachedService::class, ['someName', new Reference('externallyDefined')]));
        EcotoneSymfonyContainerFactory::build(
            $builder,
            $cacheConfiguration,
            InMemoryPSRContainer::createFromAssociativeArray(['externallyDefined' => StubLogger::create()]),
        );

        $logger = StubLogger::create();
        $loaded = EcotoneSymfonyContainerFactory::loadCached(
            $cacheConfiguration,
            InMemoryPSRContainer::createFromAssociativeArray(['externallyDefined' => $logger]),
        );

        self::assertSame($logger, $loaded->get('aService')->dependency);
    }

    public function test_bootstrap_builds_once_and_warm_boots_without_invoking_configuration_factory(): void
    {
        $cacheConfiguration = new ServiceCacheConfiguration($this->uniqueCacheDirectory(), true);
        $configurationVariableService = InMemoryConfigurationVariableService::createEmpty();
        $factoryInvocations = 0;
        $configurationFactory = function () use (&$factoryInvocations) {
            $factoryInvocations++;
            return new class () implements CompilerPass {
                public function process(ContainerBuilder $builder): void
                {
                    $builder->register('aService', new Definition(ACachedService::class, ['fromFactory']));
                }
            };
        };

        $coldBooted = EcotoneSymfonyContainerFactory::bootstrap($cacheConfiguration, $configurationVariableService, null, $configurationFactory);
        self::assertSame(1, $factoryInvocations);
        self::assertSame('fromFactory', $coldBooted->get('aService')->name);
        self::assertSame($configurationVariableService, $coldBooted->get(ConfigurationVariableService::REFERENCE_NAME));

        $warmBooted = EcotoneSymfonyContainerFactory::bootstrap($cacheConfiguration, $configurationVariableService, null, $configurationFactory);
        self::assertSame(1, $factoryInvocations);
        self::assertSame('fromFactory', $warmBooted->get('aService')->name);
    }

    public function test_load_cached_with_defaults_provides_runtime_services(): void
    {
        $cacheConfiguration = new ServiceCacheConfiguration($this->uniqueCacheDirectory(), true);
        $configurationVariableService = InMemoryConfigurationVariableService::createEmpty();
        EcotoneSymfonyContainerFactory::bootstrap(
            $cacheConfiguration,
            $configurationVariableService,
            null,
            fn () => new class () implements CompilerPass {
                public function process(ContainerBuilder $builder): void
                {
                }
            },
        );

        $loaded = EcotoneSymfonyContainerFactory::loadCachedWithDefaults($cacheConfiguration, $configurationVariableService);

        self::assertNotNull($loaded);
        self::assertSame($configurationVariableService, $loaded->get(ConfigurationVariableService::REFERENCE_NAME));
        self::assertSame($cacheConfiguration, $loaded->get(ServiceCacheConfiguration::REFERENCE_NAME));
    }

    public function test_it_instantiates_file_backed_service_from_cache_loaded_container(): void
    {
        $cacheConfiguration = new ServiceCacheConfiguration($this->uniqueCacheDirectory(), true);
        mkdir($cacheConfiguration->getPath(), 0777, true);
        $className = 'CachedFileBackedService' . uniqid();
        $filePath = $cacheConfiguration->getPath() . DIRECTORY_SEPARATOR . $className . '.php';
        file_put_contents($filePath, "<?php final class {$className} { public function greet(): string { return 'hello'; } }");

        $builder = new ContainerBuilder();
        $builder->replace('generatedService', (new Definition($className))->withFile($filePath));
        EcotoneSymfonyContainerFactory::build($builder, $cacheConfiguration);

        $loaded = EcotoneSymfonyContainerFactory::loadCached($cacheConfiguration);

        self::assertSame('hello', $loaded->get('generatedService')->greet());
    }

    public function test_compiler_pass_generates_class_into_cache_directory_and_loads_it_through_dumped_container(): void
    {
        $cacheConfiguration = new ServiceCacheConfiguration($this->uniqueCacheDirectory(), true);
        $builder = new ContainerBuilder();
        $builder->addCompilerPass(new class () implements CompilerPass {
            public function process(ContainerBuilder $builder): void
            {
                $messagingBuilder = new MessagingContainerBuilder($builder);
                $generatedClass = $messagingBuilder->generateClass(
                    'AGeneratedHandler',
                    fn (string $className) => "<?php final class {$className} { public function greet(): string { return 'generated'; } }",
                );
                $builder->register('generatedService', (new Definition($generatedClass->className))->withFile($generatedClass->filePath));
            }
        });

        $container = EcotoneSymfonyContainerFactory::build($builder, $cacheConfiguration);

        self::assertSame('generated', $container->get('generatedService')->greet());
        self::assertNotEmpty(glob($cacheConfiguration->getPath() . '/handlers/AGeneratedHandler_*.php'));

        $loaded = EcotoneSymfonyContainerFactory::loadCached($cacheConfiguration);
        self::assertSame('generated', $loaded->get('generatedService')->greet());
    }

    public function test_cache_loaded_container_serves_generated_services_after_cache_directory_removal(): void
    {
        $cacheConfiguration = new ServiceCacheConfiguration($this->uniqueCacheDirectory(), true);
        EcotoneSymfonyContainerFactory::build($this->builderWithGeneratedService('greet me'), $cacheConfiguration);

        $loaded = EcotoneSymfonyContainerFactory::loadCached($cacheConfiguration);
        foreach (glob($cacheConfiguration->getPath() . '/handlers/*.php') as $generatedFile) {
            unlink($generatedFile);
        }

        self::assertSame('greet me', $loaded->get('generatedService')->dependency->greet());
    }

    public function test_load_cached_misses_when_generated_files_are_removed(): void
    {
        $cacheConfiguration = new ServiceCacheConfiguration($this->uniqueCacheDirectory(), true);
        EcotoneSymfonyContainerFactory::build($this->builderWithGeneratedService('greet me'), $cacheConfiguration);

        foreach (glob($cacheConfiguration->getPath() . '/handlers/*.php') as $generatedFile) {
            unlink($generatedFile);
        }

        self::assertNull(EcotoneSymfonyContainerFactory::loadCached($cacheConfiguration));
    }

    private function builderWithGeneratedService(string $greeting): ContainerBuilder
    {
        $builder = new ContainerBuilder();
        $builder->addCompilerPass(new class ($greeting) implements CompilerPass {
            public function __construct(private string $greeting)
            {
            }

            public function process(ContainerBuilder $builder): void
            {
                $messagingBuilder = new MessagingContainerBuilder($builder);
                $generatedClass = $messagingBuilder->generateClass(
                    'ARemovableGeneratedHandler',
                    fn (string $className) => "<?php if (class_exists('{$className}', false)) { return; } final class {$className} { public function greet(): string { return '{$this->greeting}'; } }",
                );
                $builder->register('generatedService', new Definition(WithReferenceToUnknown::class, [
                    (new Definition($generatedClass->className))->withFile($generatedClass->filePath),
                ]));
            }
        });

        return $builder;
    }

    private function uniqueCacheDirectory(): string
    {
        return sys_get_temp_dir() . '/ecotone_container_cache_test/' . uniqid('', true);
    }
}

/**
 * licence Apache-2.0
 */
class ACachedService
{
    public function __construct(public string $name, public mixed $dependency = null)
    {
    }
}
