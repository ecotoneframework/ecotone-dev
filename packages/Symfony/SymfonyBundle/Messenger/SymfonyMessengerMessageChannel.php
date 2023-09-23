<?php

declare(strict_types=1);

namespace Ecotone\SymfonyBundle\Messenger;

use Ecotone\Messaging\Message;
use Ecotone\Messaging\PollableChannel;
use Ecotone\Messaging\Support\Assert;
use Generator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

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

        if (!is_array($symfonyEnvelope) && $symfonyEnvelope instanceof Generator && $this->symfonyTransport instanceof MessageCountAwareInterface) {
            Assert::isTrue($this->symfonyTransport->getMessageCount() === 1, 'Symfony messenger transport should be configured to return only one message at a time');
            $symfonyEnvelope = iterator_to_array($symfonyEnvelope);
        } else {
            Assert::isTrue(count($symfonyEnvelope) === 1, 'Symfony messenger transport should be configured to return only one message at a time');
        }

        return $this->symfonyMessageConverter->convertFromSymfonyMessage(
            $symfonyEnvelope[0],
            $this->symfonyTransport
        );
    }

    public function receiveWithTimeout(int $timeoutInMilliseconds): ?Message
    {
        return $this->receive();
    }
}
