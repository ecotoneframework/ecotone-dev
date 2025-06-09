<?php

declare(strict_types=1);

namespace Test\Licence;

use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\QueryBus;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;
use Symfony\App\Licence\Configuration\Kernel;

/**
 * licence Enterprise
 * @internal
 */
final class LicenceTest extends TestCase
{
    private QueryBus $queryBus;
    private CommandBus $commandBus;
    private Kernel $kernel;

    public function setUp(): void
    {
        putenv('SYMFONY_LICENCE_KEY=' . LicenceTesting::VALID_LICENCE);
        $kernel = new Kernel('dev', true);
        $kernel->boot();
        $app = $kernel->getContainer();

        $this->commandBus = $app->get(CommandBus::class);
        $this->queryBus = $app->get(QueryBus::class);
        $this->kernel = $kernel;
    }

    protected function tearDown(): void
    {
        putenv('SYMFONY_LICENCE_KEY');

        restore_exception_handler();
    }

    public function test_triggering_symfony_with_licence_key(): void
    {
        $this->commandBus->sendWithRouting('sendNotification');

        $this->expectNotToPerformAssertions();
    }
}
