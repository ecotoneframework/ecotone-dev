<?php

declare(strict_types=1);

namespace Test\HeadersInLogContext;

use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Messaging\Config\ConfigurationException;
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

    public function test_headers_listed_in_a_service_context_are_in_the_log_context(): void
    {
        $container = self::bootWith([]);

        $container->get(CommandBus::class)->sendWithRouting('setAmount', ['amount' => 100], metadata: ['userId' => 'user-7', 'region' => 'eu']);

        $context = $container->get('logger')->contextOf('Executing Command Handler');
        self::assertSame('user-7', $context['userId']);
        self::assertArrayNotHasKey('region', $context);
    }

    public function test_the_headers_in_log_context_bundle_key_is_refused_naming_the_service_context_replacement(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("Ecotone does not recognise the `headersInLogContext` key in the `ecotone` bundle configuration. Since Ecotone 2.0 it is set on ServiceConfiguration, returned from a #[ServiceContext] method: ServiceConfiguration::createWithDefaults()->withHeadersInLogContext(['userId']). Move it there and remove the key. The keys Ecotone reads in the `ecotone` bundle configuration are: enabledModulePackageNames, licenceKey, loadSrcNamespaces, namespaces, test.");

        self::bootWith(['headersInLogContext' => ['userId']]);
    }

    private static function bootWith(array $ecotoneConfiguration): ContainerInterface
    {
        $kernel = new HeadersInLogContextKernel($ecotoneConfiguration);
        $kernel->boot();

        return $kernel->getContainer();
    }
}
