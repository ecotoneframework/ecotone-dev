<?php

declare(strict_types=1);

namespace Ecotone\SymfonyBundle\Messenger;

use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageConverter\HeaderMapper;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\PollableChannel;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Messaging\Support\MessageBuilder;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
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

        Assert::isTrue(count($symfonyEnvelope) === 1, 'Symfony messenger transport should be configured to return only one message at a time');

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
