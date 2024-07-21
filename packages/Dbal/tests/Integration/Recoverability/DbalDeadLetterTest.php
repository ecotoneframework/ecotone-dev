<?php

namespace Test\Ecotone\Dbal\Integration\Recoverability;

use Ecotone\Dbal\Recoverability\DbalDeadLetterHandler;
use Ecotone\Messaging\Handler\MessageHandlingException;
use Ecotone\Messaging\Handler\Recoverability\ErrorContext;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageConverter\DefaultHeaderMapper;
use Ecotone\Messaging\Support\ErrorMessage;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Test\InMemoryConversionService;
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
    public function test_retrieving_error_message_details()
    {
        $dbalDeadLetter = new DbalDeadLetterHandler($this->getConnectionFactory(), DefaultHeaderMapper::createAllHeadersMapping(), InMemoryConversionService::createWithoutConversion());

        $errorMessage = MessageBuilder::withPayload('')->build();
        $dbalDeadLetter->store($errorMessage);

        $this->assertEquals(
            $errorMessage,
            $dbalDeadLetter->show($errorMessage->getHeaders()->getMessageId())
        );
    }

    public function test_storing_wrapped_error_message()
    {
        $dbalDeadLetter = new DbalDeadLetterHandler($this->getConnectionFactory(), DefaultHeaderMapper::createAllHeadersMapping(), InMemoryConversionService::createWithoutConversion());


        $errorMessage = MessageBuilder::withPayload('')->build();
        $dbalDeadLetter->store($this->createFailedMessage($errorMessage));

        $this->assertEquals(
            $errorMessage->getHeaders()->getMessageId(),
            $dbalDeadLetter->show($errorMessage->getHeaders()->getMessageId())->getHeaders()->getMessageId()
        );
    }

    private function createFailedMessage(Message $message, Throwable $exception = null): Message
    {
        return ErrorMessage::create(MessageHandlingException::fromOtherException($exception ?? new MessageHandlingException(), $message));
    }

    public function test_listing_error_messages()
    {
        $dbalDeadLetter = new DbalDeadLetterHandler($this->getConnectionFactory(), DefaultHeaderMapper::createAllHeadersMapping(), InMemoryConversionService::createWithoutConversion());

        $errorMessage = MessageBuilder::withPayload('error1')
                                ->setMultipleHeaders([
                                    ErrorContext::EXCEPTION_STACKTRACE => '#12',
                                    ErrorContext::EXCEPTION_LINE => 120,
                                    ErrorContext::EXCEPTION_FILE => 'dbalDeadLetter.php',
                                    ErrorContext::EXCEPTION_CODE => 1,
                                    ErrorContext::EXCEPTION_MESSAGE => 'some',
                                ])
                                ->build();
        $dbalDeadLetter->store($errorMessage);

        $this->assertEquals(
            [ErrorContext::fromHeaders($errorMessage->getHeaders()->headers())],
            $dbalDeadLetter->list(1, 0)
        );
    }

    public function test_deleting_error_message()
    {
        $dbalDeadLetter = new DbalDeadLetterHandler($this->getConnectionFactory(), DefaultHeaderMapper::createAllHeadersMapping(), InMemoryConversionService::createWithoutConversion());

        $message = MessageBuilder::withPayload('error2')->build();

        $this->assertEquals(0, $dbalDeadLetter->count());

        $dbalDeadLetter->store($message);
        $this->assertEquals(1, $dbalDeadLetter->count());

        $dbalDeadLetter->delete($message->getHeaders()->getMessageId());

        $this->assertEquals([], $dbalDeadLetter->list(1, 0));
        $this->assertEquals(0, $dbalDeadLetter->count());
    }
}
