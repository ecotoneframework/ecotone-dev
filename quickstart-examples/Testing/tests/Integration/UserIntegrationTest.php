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
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class UserIntegrationTest extends TestCase
{
    public function test_sending_command_as_json()
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [User::class],
            [new EmailConverter(), new PhoneNumberConverter(), new UuidConverter()],
            ServiceConfiguration::createWithDefaults()
                ->withNamespaces(["App\Testing\Infrastructure\Converter"])
                ->withModulePackages([ModulePackageList::JMS_CONVERTER_PACKAGE])
                ->withExtensionObjects([
                    InMemoryRepositoryBuilder::createForAllStateStoredAggregates(),
                ]),
            pathToRootCatalog: __DIR__,
        );

        $ecotoneLite->sendCommandWithRoutingKey("user.register", \json_encode([
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
            $ecotoneLite->getRecordedEvents()
        );
    }
}