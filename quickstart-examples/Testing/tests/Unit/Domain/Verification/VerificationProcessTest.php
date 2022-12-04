<?php

declare(strict_types=1);

namespace App\Testing\Domain\Verification;

use App\Testing\Domain\User\Email;
use App\Testing\Domain\User\Event\UserWasRegistered;
use App\Testing\Domain\User\PhoneNumber;
use App\Testing\Domain\Verification\Command\StartEmailVerification;
use App\Testing\Domain\Verification\Command\StartPhoneNumberVerification;
use App\Testing\Infrastructure\MessagingConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\Configuration\InMemoryRepositoryBuilder;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\CloseTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasClosed;

final class VerificationProcessTest extends TestCase
{
    public function test_starting_verification_process()
    {
        $emailToken = "123";
        $phoneNumberToken = "12345";
        $tokenGenerator = new StubTokenGenerator([$emailToken, $phoneNumberToken]);
        $ecotoneTestSupport = EcotoneLite::bootstrapForTesting(
            [VerificationProcess::class],
            [TokenGenerator::class => $tokenGenerator],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE]))
                ->withExtensionObjects([
                    InMemoryRepositoryBuilder::createForAllEventSourcedAggregates(),
                    SimpleMessageChannelBuilder::createNullableChannel(MessagingConfiguration::ASYNCHRONOUS_MESSAGES)
                ]),
        );

        $userId = Uuid::uuid4();
        $email = Email::create('test@wp.pl');
        $phoneNumber = PhoneNumber::create('148518518518');

        $this->assertEquals(
            [
                new StartEmailVerification($email, VerificationToken::from($emailToken)),
                new StartPhoneNumberVerification($phoneNumber, VerificationToken::from($phoneNumberToken))
            ],
            $ecotoneTestSupport->getFlowTestSupport()
                ->publishEvent(new UserWasRegistered(
                    $userId,
                    $email,
                    $phoneNumber
                ))
                ->getRecordedCommands()
        );
    }

    public function test_tokens_were_not_verified_so_user_will_be_blocked()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapForTesting(
            [VerificationProcess::class],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE]))
                ->withExtensionObjects([
                    InMemoryRepositoryBuilder::createForAllEventSourcedAggregates(),
                    SimpleMessageChannelBuilder::createQueueChannel(MessagingConfiguration::ASYNCHRONOUS_MESSAGES, true)
                ]),
        );

        $userId = Uuid::uuid4();

        $this->assertEquals(
            [

            ],
            $ecotoneTestSupport->getFlowTestSupport()
                ->publishEvent(new UserWasRegistered(
                    $userId,
                    Email::create('test@wp.pl'),
                    PhoneNumber::create('148518518518')
                ))
                ->getRecordedCommands()
        );
    }
}