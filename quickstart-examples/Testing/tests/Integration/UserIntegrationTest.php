<?php

declare(strict_types=1);

namespace Test\App\Integration;

use App\Testing\Domain\User\Email;
use App\Testing\Domain\User\Event\UserWasRegistered;
use App\Testing\Domain\User\PhoneNumber;
use App\Testing\Domain\User\User;
use App\Testing\Infrastructure\Converter\EmailConverter;
use App\Testing\Infrastructure\Converter\PhoneNumberConverter;
use App\Testing\Infrastructure\Converter\UuidConverter;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\Configuration\InMemoryRepositoryBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class UserIntegrationTest extends TestCase
{
    public function test_sending_command_as_json()
    {
        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            // classes to resolve
            [User::class],
            // available services, you may inject container instead
            [new EmailConverter(), new PhoneNumberConverter(), new UuidConverter()],
            configuration: ServiceConfiguration::createWithDefaults()
                                // resolve all classes from Converter namespace
                                ->withNamespaces(["App\Testing\Infrastructure\Converter"])
                                ->withSkippedModulePackageNames([ModulePackageList::ASYNCHRONOUS_PACKAGE])
                                ->withExtensionObjects([
                                    // register in memory repository for User
                                    InMemoryRepositoryBuilder::createForAllStateStoredAggregates()
                                ]),
            pathToRootCatalog: __DIR__ // can be ignored, needed for running inside ecotone-dev monorepo
        );

        $ecotoneLite->getCommandBus()->sendWithRouting("user.register", \json_encode([
            "userId" => "7dd60feb-c23c-4ddb-9d53-5354349becaa",
            "name" => "johny",
            "email" => "test@wp.pl",
            "phoneNumber" => "148518518518",
        ]), commandMediaType: "application/json");

        /** Comparing published events after registration */
        $this->assertEquals(
            [new UserWasRegistered(
                Uuid::fromString("7dd60feb-c23c-4ddb-9d53-5354349becaa"),
                Email::create("test@wp.pl"),
                PhoneNumber::create("148518518518")
            )],
            // Make use of Test Support Gateway to find published events
            $ecotoneLite->getMessagingTestSupport()->getRecordedEvents()
        );
    }
}