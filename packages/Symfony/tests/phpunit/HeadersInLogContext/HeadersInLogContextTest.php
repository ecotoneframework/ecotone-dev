<?php

declare(strict_types=1);

namespace Test\HeadersInLogContext;

use Ecotone\Api\Gateway\CommandBus;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * licence Apache-2.0
 * @internal
 */
#[RunTestsInSeparateProcesses]
final class HeadersInLogContextTest extends TestCase
{
    protected function setUp(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . '/ecotone_symfony_headers_in_log_context');
    }

    public function test_headers_listed_in_the_bundle_configuration_are_in_the_log_context(): void
    {
        $container = self::bootWith(['headersInLogContext' => ['userId']]);

        $container->get(CommandBus::class)->sendWithRouting('setAmount', ['amount' => 100], metadata: ['userId' => 'user-7', 'region' => 'eu']);

        $context = $container->get('logger')->contextOf('Executing Command Handler');
        self::assertSame('user-7', $context['userId']);
        self::assertArrayNotHasKey('region', $context);
    }

    private static function bootWith(array $ecotoneConfiguration): ContainerInterface
    {
        $kernel = new HeadersInLogContextKernel($ecotoneConfiguration);
        $kernel->boot();

        return $kernel->getContainer();
    }
}
