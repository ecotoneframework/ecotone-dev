<?php

namespace Test;

use Ecotone\Lite\Test\MessagingTestSupport;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @internal
 */
class SymfonyApplicationTest extends KernelTestCase
{
    public function test_it_boots_kernel_with_test_support(): void
    {
        self::bootKernel([
            'environment' => 'test',
        ]);
        self::assertInstanceOf(
            MessagingTestSupport::class,
            self::getMessagingTestSupport()
        );
    }

    protected static function getMessagingSystem(): ConfiguredMessagingSystem
    {
        return static::getContainer()->get(ConfiguredMessagingSystem::class);
    }

    protected static function getMessagingTestSupport(): MessagingTestSupport
    {
        return static::getMessagingSystem()->getGatewayByName(MessagingTestSupport::class);
    }
}
