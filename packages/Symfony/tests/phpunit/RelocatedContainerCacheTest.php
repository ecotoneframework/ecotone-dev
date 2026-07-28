<?php

declare(strict_types=1);

namespace Test;

use Ecotone\Lite\InMemoryPSRContainer;
use Ecotone\Messaging\Config\Container\Compiler\RegisterInterfaceToCallReferences;
use Ecotone\Messaging\Config\Container\Compiler\ValidityCheckPass;
use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Ecotone\Messaging\Config\Container\GatewayProxyReference;
use Ecotone\Messaging\Config\MessagingSystemConfiguration;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Ecotone\Messaging\Handler\Gateway\ProxyFactory;
use Ecotone\Modelling\QueryBus;
use Ecotone\SymfonyBundle\DependencyInjection\EcotoneContainerLoader;
use Ecotone\SymfonyContainer\EcotoneSymfonyContainerFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class RelocatedContainerCacheTest extends TestCase
{
    private Filesystem $filesystem;
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->temporaryDirectory = sys_get_temp_dir() . '/ecotone-relocation-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->temporaryDirectory);
    }

    public function test_cached_proxies_are_written_next_to_the_relocated_container(): void
    {
        $buildCacheDirectory = $this->temporaryDirectory . '/build/ecotone';
        $runtimeCacheDirectory = $this->temporaryDirectory . '/runtime/ecotone';

        $this->buildEcotoneContainerIn($buildCacheDirectory);
        $this->relocateCacheFrom($buildCacheDirectory, $runtimeCacheDirectory);

        $container = EcotoneContainerLoader::load($runtimeCacheDirectory, InMemoryPSRContainer::createEmpty());

        $proxyFile = $container
            ->get(ProxyFactory::class)
            ->generateCachedProxyFileFor(new GatewayProxyReference(QueryBus::class, QueryBus::class), true);

        self::assertStringStartsWith($runtimeCacheDirectory . '/', $proxyFile);
        self::assertFileExists($proxyFile);
    }

    private function buildEcotoneContainerIn(string $cacheDirectory): void
    {
        $serviceCacheConfiguration = new ServiceCacheConfiguration($cacheDirectory, true);

        $containerBuilder = new ContainerBuilder();
        $containerBuilder->register(ServiceCacheConfiguration::REFERENCE_NAME, $serviceCacheConfiguration);
        $containerBuilder->addCompilerPass(MessagingSystemConfiguration::prepareWithDefaultsForTesting());
        $containerBuilder->addCompilerPass(new RegisterInterfaceToCallReferences());
        $containerBuilder->addCompilerPass(new ValidityCheckPass());

        MessagingSystemConfiguration::prepareCacheDirectory($serviceCacheConfiguration);
        EcotoneSymfonyContainerFactory::build($containerBuilder, $serviceCacheConfiguration);
    }

    private function relocateCacheFrom(string $buildCacheDirectory, string $runtimeCacheDirectory): void
    {
        $this->filesystem->mirror($buildCacheDirectory, $runtimeCacheDirectory);
        $this->filesystem->remove($this->temporaryDirectory . '/build');
        $this->filesystem->touch($this->temporaryDirectory . '/build');
    }
}
