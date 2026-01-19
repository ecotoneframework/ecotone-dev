<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

use Ecotone\Messaging\Config\ModulePackageList;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\TestCase;

class SymfonyEventSourcingIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        restore_exception_handler();
    }

    #[DoesNotPerformAssertions]
    public function test_symfony_in_test_mode_with_es_should_boot(): void
    {
        \putenv(sprintf('APP_SKIPPED_PACKAGES=%s', \json_encode(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE]), JSON_THROW_ON_ERROR)));
        $kernel = new \Monorepo\ExampleApp\Symfony\Kernel('test_es', false);
        $kernel->boot();
    }
}