<?php

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Illuminate\Foundation\Http\Kernel as LaravelKernel;
use Monorepo\Benchmark\FullAppBenchmarkCase;
use Monorepo\ExampleApp\ExampleAppCaseTrait;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpKernel\Kernel as SymfonyKernel;

class ProxyCacheGenerationTest extends FullAppBenchmarkCase
{
    use ExampleAppCaseTrait;

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_symfony_prod()
    {
        $this->markTestSkipped('grpc warning to be solved: https://github.com/google-ai-edge/mediapipe/issues/5371#issuecomment-2219871932');

        self::clearSymfonyCache();
        $this->bench_symfony_prod();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_laravel_prod(): void
    {
        $this->markTestSkipped('grpc warning to be solved: https://github.com/google-ai-edge/mediapipe/issues/5371#issuecomment-2219871932');

        self::clearLaravelCache();
        $this->bench_laravel_prod();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_lite_application_prod()
    {
        $this->markTestSkipped('grpc warning to be solved: https://github.com/google-ai-edge/mediapipe/issues/5371#issuecomment-2219871932');

        self::clearLiteApplicationCache();
        $this->bench_lite_application_prod();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_lite_prod()
    {
        $this->markTestSkipped('grpc warning to be solved: https://github.com/google-ai-edge/mediapipe/issues/5371#issuecomment-2219871932');

        self::clearLiteCache();
        $this->bench_lite_prod();
    }

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