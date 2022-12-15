<?php

declare(strict_types=1);

namespace Test\App\Unit\Domain\Verification;

use App\Testing\Domain\User\Email;
use App\Testing\Domain\User\Event\UserWasRegistered;
use App\Testing\Domain\User\PhoneNumber;
use App\Testing\Domain\Verification\Command\StartEmailVerification;
use App\Testing\Domain\Verification\Command\StartPhoneNumberVerification;
use App\Testing\Domain\Verification\Command\VerifyEmail;
use App\Testing\Domain\Verification\Command\VerifyPhoneNumber;
use App\Testing\Domain\Verification\TokenGenerator;
use App\Testing\Domain\Verification\VerificationProcess;
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

final class VerificationProcessTest extends TestCase
{
    public function test_starting_verification_process()
    {
        $emailToken = "123";
        $phoneNumberToken = "12345";
        $tokenGenerator = new StubTokenGenerator([$emailToken, $phoneNumberToken]);

        $userId = Uuid::uuid4();
        $email = Email::create('test@wp.pl');
        $phoneNumber = PhoneNumber::create('148518518518');

        /** Testing Saga flow, publishing event and verifying sent commands */
        $this->assertEquals(
            [
                new StartEmailVerification($email, VerificationToken::from($emailToken)),
                new StartPhoneNumberVerification($phoneNumber, VerificationToken::from($phoneNumberToken))
            ],
            $this->bootstrapFlowTesting($tokenGenerator)
                ->publishEvent(new UserWasRegistered($userId, $email, $phoneNumber))
                ->getRecordedCommands()
        );
    }

    public function test_tokens_verified_so_user_is_verified()
    {
        $emailToken = "123";
        $phoneNumberToken = "12345";
        $tokenGenerator = new StubTokenGenerator([$emailToken, $phoneNumberToken]);

        $userId = Uuid::uuid4();
        $email = Email::create('test@wp.pl');
        $phoneNumber = PhoneNumber::create('148518518518');

        $this->assertEquals(
            [["user.verify", $userId->toString()]],
            $this->bootstrapFlowTesting($tokenGenerator)
                ->publishEvent(new UserWasRegistered($userId, $email, $phoneNumber))
                ->sendCommand(new VerifyEmail($userId, VerificationToken::from($emailToken)))
                ->discardRecordedMessages()
                ->sendCommand(new VerifyPhoneNumber($userId, VerificationToken::from($phoneNumberToken)))
                ->releaseAwaitingMessagesAndRunConsumer(MessagingConfiguration::ASYNCHRONOUS_MESSAGES, 1000 * 60 * 60 * 24)
                ->getRecordedCommandsWithRouting()
        );
    }

    public function test_at_least_one_token_not_verified_so_user_is_blocked()
    {
        $emailToken = "123";
        $phoneNumberToken = "12345";
        $tokenGenerator = new StubTokenGenerator([$emailToken, $phoneNumberToken]);

        $userId = Uuid::uuid4();
        $email = Email::create('test@wp.pl');
        $phoneNumber = PhoneNumber::create('148518518518');

        $this->assertEquals(
            [['user.block', $userId->toString()]],
            $this->bootstrapFlowTesting($tokenGenerator)
                ->publishEvent(new UserWasRegistered($userId, $email, $phoneNumber))
                ->sendCommand(new VerifyEmail($userId, VerificationToken::from($emailToken)))
                ->discardRecordedMessages()
                ->releaseAwaitingMessagesAndRunConsumer(MessagingConfiguration::ASYNCHRONOUS_MESSAGES, 1000 * 60 * 60 * 24)
                ->getRecordedCommandsWithRouting()
        );
    }

    private function bootstrapFlowTesting(StubTokenGenerator $tokenGenerator): \Ecotone\Lite\Test\FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            [VerificationProcess::class],
            [TokenGenerator::class => $tokenGenerator],
            ServiceConfiguration::createWithDefaults()
                /** We want to enable asynchronous package to test delays */
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createQueueChannel(MessagingConfiguration::ASYNCHRONOUS_MESSAGES, true),
                    PollingMetadata::create(MessagingConfiguration::ASYNCHRONOUS_MESSAGES)->withTestingSetup(),
                    /** We don't want command bus to fail, when command handler is not found, as we want to assert if commands were sent */
                    TestConfiguration::createWithDefaults()->withFailOnCommandHandlerNotFound(false)
                ]),
        );
    }
}