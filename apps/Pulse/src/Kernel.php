<?php

declare(strict_types=1);

namespace App;

use App\Settings\PulseEnvironment;
use App\Settings\PulseSettings;
use App\Settings\Runtime;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    protected function build(ContainerBuilder $container): void
    {
        $settings = PulseSettings::fromEnvironment(PulseEnvironment::read(), $this->getProjectDir());
        Runtime::fromEnvironment(PulseEnvironment::read());
        Runtime::consumerCoroutines(PulseEnvironment::read());
        Runtime::httpWorkers(PulseEnvironment::read());
        $container->loadFromExtension('ecotone', [
            'licenceKey' => '%env(ECOTONE_LICENCE_KEY)%',
            'enabledModulePackageNames' => $settings->modulePackages(),
        ]);
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '/' . substr(md5(serialize(PulseEnvironment::read())), 0, 12);
    }
}
