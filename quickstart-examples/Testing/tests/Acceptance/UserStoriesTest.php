<?php

declare(strict_types=1);

namespace Test\Acceptance;

use App\Testing\Domain\User\Command\RegisterUser;
use App\Testing\Domain\User\Email;
use App\Testing\Domain\User\Event\UserWasRegistered;
use App\Testing\Domain\User\PhoneNumber;
use App\Testing\Domain\User\User;
use App\Testing\Domain\Verification\Command\StartEmailVerification;
use App\Testing\Domain\Verification\Command\StartPhoneNumberVerification;
use App\Testing\Domain\Verification\Command\VerifyEmail;
use App\Testing\Domain\Verification\Command\VerifyPhoneNumber;
use App\Testing\Domain\Verification\TokenGenerator;
use App\Testing\Domain\Verification\VerificationProcess;
use App\Testing\Domain\Verification\VerificationSender;
use App\Testing\Domain\Verification\VerificationToken;
use App\Testing\Infrastructure\MessagingConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\Configuration\InMemoryRepositoryBuilder;
use Ecotone\Lite\Test\TestConfiguration;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Test\App\Fixture\StubTokenGenerator;

final class UserStoriesTest extends TestCase
{
    public function test_success_verification_after_registration()
    {
        $emailToken = "123";
        $phoneNumberToken = "12345";
        $tokenGenerator = new StubTokenGenerator([$emailToken, $phoneNumberToken]);

        $ecotoneTestSupport = EcotoneLite::bootstrapForTesting(
            [User::class, VerificationProcess::class, VerificationSender::class],
            [TokenGenerator::class => $tokenGenerator, new VerificationSender()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    InMemoryRepositoryBuilder::createForAllStateStoredAggregates(),
                    SimpleMessageChannelBuilder::createQueueChannel(MessagingConfiguration::ASYNCHRONOUS_MESSAGES, true),
                    PollingMetadata::create(MessagingConfiguration::ASYNCHRONOUS_MESSAGES)->withTestingSetup()
                ]),
        );

        $userId = Uuid::uuid4();
        $email = Email::create('test@wp.pl');
        $phoneNumber = PhoneNumber::create('148518518518');

        $this->assertEquals(
            ["user.verify"],
            $ecotoneTestSupport->getFlowTestSupport()
                ->sendCommand(new RegisterUser($userId, "John Snow", $email, $phoneNumber))
                ->sendCommand(new VerifyEmail($userId, VerificationToken::from($emailToken)))
                ->sendCommand(new VerifyPhoneNumber($userId, VerificationToken::from($phoneNumberToken)))
                ->discardRecordedMessages()
                ->releaseAwaitingMessagesAndRunConsumer(MessagingConfiguration::ASYNCHRONOUS_MESSAGES, 1000 * 60 * 60 * 24)
                ->getRecordedCommandRouting()
        );
    }
}