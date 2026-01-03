<?php

namespace Test\Ecotone\Dbal\Integration\Recoverability;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\Recoverability\DbalDeadLetterHandler;
use Ecotone\Dbal\Recoverability\DeadLetterGateway;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\MessageHandlingException;
use Ecotone\Messaging\Handler\Recoverability\ErrorContext;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\ErrorMessage;
use Ecotone\Messaging\Support\MessageBuilder;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Throwable;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
class DbalDeadLetterTest extends DbalMessagingTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->cleanUpTables();
    }

    public function tearDown(): void
    {
        parent::tearDown();
        $this->cleanUpTables();
    }

    public function test_retrieving_error_message_details()
    {
        $ecotone = $this->bootstrapEcotone();
        $gateway = $ecotone->getGateway(DeadLetterGateway::class);

        $errorMessage = MessageBuilder::withPayload('')->build();
        $gateway->store($errorMessage);

        $retrievedMessage = $gateway->show($errorMessage->getHeaders()->getMessageId());

        $this->assertEquals($errorMessage->getPayload(), $retrievedMessage->getPayload());
        $this->assertEquals($errorMessage->getHeaders()->getMessageId(), $retrievedMessage->getHeaders()->getMessageId());
    }

    public function test_storing_wrapped_error_message()
    {
        $ecotone = $this->bootstrapEcotone();
        $gateway = $ecotone->getGateway(DeadLetterGateway::class);

        $errorMessage = MessageBuilder::withPayload('')->build();
        $gateway->store($this->createFailedMessage($errorMessage));

        $this->assertEquals(
            $errorMessage->getHeaders()->getMessageId(),
            $gateway->show($errorMessage->getHeaders()->getMessageId())->getHeaders()->getMessageId()
        );
    }

    private function createFailedMessage(Message $message, ?Throwable $exception = null): Message
    {
        return ErrorMessage::create($message, $exception ?? new MessageHandlingException());
    }

    public function test_listing_error_messages()
    {
        $ecotone = $this->bootstrapEcotone();
        $gateway = $ecotone->getGateway(DeadLetterGateway::class);

        $errorMessage = MessageBuilder::withPayload('error1')
                                ->setMultipleHeaders([
                                    ErrorContext::EXCEPTION_STACKTRACE => '#12',
                                    ErrorContext::EXCEPTION_LINE => 120,
                                    ErrorContext::EXCEPTION_FILE => 'dbalDeadLetter.php',
                                    ErrorContext::EXCEPTION_CODE => 1,
                                    ErrorContext::EXCEPTION_MESSAGE => 'some',
                                ])
                                ->build();
        $gateway->store($errorMessage);

        $this->assertEquals(
            [ErrorContext::fromHeaders($errorMessage->getHeaders()->headers())],
            $gateway->list(1, 0)
        );
    }

    public function test_deleting_error_message()
    {
        $ecotone = $this->bootstrapEcotone();
        $gateway = $ecotone->getGateway(DeadLetterGateway::class);

        $message = MessageBuilder::withPayload('error2')->build();

        $this->assertEquals(0, $gateway->count());

        $gateway->store($message);
        $this->assertEquals(1, $gateway->count());

        $gateway->delete($message->getHeaders()->getMessageId());

        $this->assertEquals([], $gateway->list(1, 0));
        $this->assertEquals(0, $gateway->count());
    }

    private function bootstrapEcotone(): FlowTestSupport
    {
        $connectionFactory = $this->getConnectionFactory();

        return EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [
                DbalConnectionFactory::class => $connectionFactory,
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()
                        ->withAutomaticTableInitialization(true),
                ]),
            pathToRootCatalog: __DIR__ . '/../../../',
        );
    }

    private function cleanUpTables(): void
    {
        $connection = $this->getConnection();
        if (self::checkIfTableExists($connection, DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE)) {
            $connection->executeStatement('DROP TABLE ' . DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE);
        }
    }
}
