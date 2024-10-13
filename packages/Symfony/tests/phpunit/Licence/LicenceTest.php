<?php

declare(strict_types=1);

namespace Test\Licence;

use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\QueryBus;
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
        $kernel = new Kernel('dev', true);
        $kernel->boot();
        $app = $kernel->getContainer();

        $this->commandBus = $app->get(CommandBus::class);
        $this->queryBus = $app->get(QueryBus::class);
        $this->kernel = $kernel;
    }

    public function test_using_enterprise_feature(): void
    {
        $this->commandBus->sendWithRouting('sendNotification');

        $this->expectNotToPerformAssertions();
    }
}
