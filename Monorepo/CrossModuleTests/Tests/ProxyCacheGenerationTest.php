<?php

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Illuminate\Foundation\Http\Kernel as LaravelKernel;
use Monorepo\CrossModuleTests\Tests\FullAppTestCase;
use Monorepo\ExampleApp\ExampleAppCaseTrait;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpKernel\Kernel as SymfonyKernel;

class ProxyCacheGenerationTest extends FullAppTestCase
{
    use ExampleAppCaseTrait;

    public function executeForSymfony(ContainerInterface $container, SymfonyKernel $kernel): void
    {
        $this->execute($container->get(ConfiguredMessagingSystem::class));
    }

    public function executeForLaravel(ContainerInterface $container, LaravelKernel $kernel): void
    {
        $this->execute($container->get(ConfiguredMessagingSystem::class));
    }

    public function executeForLiteApplication(ContainerInterface $container): void
    {
        $this->execute($container->get(ConfiguredMessagingSystem::class));
    }

    public function executeForLite(ConfiguredMessagingSystem $messagingSystem): void
    {
        $this->execute($messagingSystem);
    }

    private function execute(ConfiguredMessagingSystem $messagingSystem)
    {
        if (getenv('APP_ENV') !== 'prod') {
            $this->markTestSkipped('Proxy cache must be warmed up only for prod environment');
        }
        $commandBusProxy = $messagingSystem->getCommandBus();
        $reflection = new ReflectionClass($commandBusProxy);
        $filename = $reflection->getFileName();
        self::assertFalse(self::isEvalCode($filename), 'Proxy class should not be generated as eval code');
    }

    private static function isEvalCode(string $fileName): bool
    {
        return strpos($fileName, "eval()'d code") !== false;
    }
}