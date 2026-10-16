<?php

declare(strict_types=1);

namespace Test\EnabledModulePackageNames;

use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\SymfonyBundle\EcotoneSymfonyBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

/**
 * licence Apache-2.0
 */
final class EnabledModulePackageNamesKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct(private array $ecotoneConfiguration)
    {
        parent::__construct('test', true);
    }

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new EcotoneSymfonyBundle()];
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/ecotone_symfony_enabled_module_package_names/' . md5(serialize($this->ecotoneConfiguration));
    }

    public function getLogDir(): string
    {
        return $this->getCacheDir() . '/log';
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('ecotone', ['loadSrcNamespaces' => false, 'namespaces' => ['Test\\EnabledModulePackageNames\\Application']]);
        $container->extension('ecotone', $this->ecotoneConfiguration);
        $container->services()->set(DbalConnectionFactory::class)->args(['pgsql://ecotone:secret@localhost:5432/ecotone']);
    }
}
