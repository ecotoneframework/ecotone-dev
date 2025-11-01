<?php

declare(strict_types=1);

namespace Ecotone\SymfonyBundle\Messenger;

use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\PollableChannel;
use Ecotone\Messaging\Support\Assert;
use Generator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * licence Apache-2.0
 */
final class SymfonyMessengerMessageChannel implements PollableChannel
{
    public function __construct(
        private TransportInterface $symfonyTransport,
        private SymfonyMessageConverter $symfonyMessageConverter
    ) {

    }

    public function send(Message $message): void
    {
        $this->symfonyTransport->send(
            $this->symfonyMessageConverter->convertToSymfonyMessage($message, true)
        );
    }

    public function receive(): ?Message
    {
        /** @var Envelope[] $symfonyEnvelope */
        $symfonyEnvelope = $this->symfonyTransport->get();

        if ($symfonyEnvelope === []) {
            return null;
        }

        if ($symfonyEnvelope instanceof Generator) {
            $symfonyEnvelope = iterator_to_array($symfonyEnvelope);
            if ($symfonyEnvelope === []) {
                return null;
            }
        } else {
            Assert::isTrue(count($symfonyEnvelope) === 1, 'Symfony messenger transport should be configured to return only one message at a time');
        }

        return $this->symfonyMessageConverter->convertFromSymfonyMessage(
            $symfonyEnvelope[0],
            $this->symfonyTransport
        );
    }

    public function receiveWithTimeout(PollingMetadata $pollingMetadata): ?Message
    {
        return $this->receive();
    }

    public function onConsumerStop(): void
    {
        // No cleanup needed for Symfony messenger channels
    }
}
