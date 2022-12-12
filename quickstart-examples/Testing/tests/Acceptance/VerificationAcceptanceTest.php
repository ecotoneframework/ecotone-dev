<?php

declare(strict_types=1);

namespace Test\App\Acceptance;

use App\Testing\Domain\User\Command\RegisterUser;
use App\Testing\Domain\User\Email;
use App\Testing\Domain\User\PhoneNumber;
use App\Testing\Domain\User\User;
use App\Testing\Domain\Verification\Command\VerifyEmail;
use App\Testing\Domain\Verification\Command\VerifyPhoneNumber;
use App\Testing\Domain\Verification\TokenGenerator;
use App\Testing\Domain\Verification\VerificationProcess;
use App\Testing\Domain\Verification\VerificationSender;
use App\Testing\Domain\Verification\VerificationToken;
use App\Testing\Infrastructure\MessagingConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Test\App\Fixture\StubTokenGenerator;

final class VerificationAcceptanceTest extends TestCase
{
    public function test_success_verification_after_registration()
    {
        $emailToken = "123";
        $phoneNumberToken = "12345";
        $tokenGenerator = new StubTokenGenerator([$emailToken, $phoneNumberToken]);
        $userId = Uuid::uuid4();
        $email = Email::create('test@wp.pl');
        $phoneNumber = PhoneNumber::create('148518518518');

        $this->assertEquals(
            [["user.verify", $userId->toString()]],
            $this->bootstrapTestSupport($tokenGenerator)
                ->sendCommand(new RegisterUser($userId, "John Snow", $email, $phoneNumber))
                ->sendCommand(new VerifyEmail($userId, VerificationToken::from($emailToken)))
                ->discardRecordedMessages()
                ->sendCommand(new VerifyPhoneNumber($userId, VerificationToken::from($phoneNumberToken)))
                ->releaseAwaitingMessagesAndRunConsumer(MessagingConfiguration::ASYNCHRONOUS_MESSAGES, 1000 * 60 * 60 * 24)
                ->getRecordedCommandsWithRouting()
        );
    }

    private function bootstrapTestSupport(StubTokenGenerator $tokenGenerator): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            [User::class, VerificationProcess::class, VerificationSender::class],
            [TokenGenerator::class => $tokenGenerator, new VerificationSender()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createQueueChannel(MessagingConfiguration::ASYNCHRONOUS_MESSAGES, true),
                    PollingMetadata::create(MessagingConfiguration::ASYNCHRONOUS_MESSAGES)->withTestingSetup()
                ]),
        );
    }
}