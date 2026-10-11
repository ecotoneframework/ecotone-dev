<?php

declare(strict_types=1);

namespace Test\Licence;

use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\QueryBus;
use Ecotone\SymfonyBundle\DependencyInjection\Compiler\CacheClearer;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;
use Symfony\App\Licence\Configuration\Kernel;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
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

        $this->kernel->getContainer()->get(CacheClearer::class)->clear('');
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
