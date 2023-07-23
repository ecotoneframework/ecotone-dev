<?php

declare(strict_types=1);

namespace Ecotone\SymfonyBundle\Messenger;

use Ecotone\Enqueue\EnqueueAcknowledgementCallback;
use Ecotone\Messaging\Endpoint\AcknowledgementCallback;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Class EnqueueAcknowledgementCallback
 * @package Ecotone\Amqp
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class SymfonyAcknowledgementCallback implements AcknowledgementCallback
{
    public const AUTO_ACK = 'auto';
    public const MANUAL_ACK = 'manual';
    public const NONE = 'none';

    private function __construct(
        private bool $isAutoAck, 
        private TransportInterface $symfonyTransport,
        private Envelope $envelope
    )
    {

    }

    public static function createWithAutoAck(TransportInterface $symfonyTransport, Envelope $envelope): self
    {
        return new self(true, $symfonyTransport, $envelope);
    }

    public static function createWithManualAck(TransportInterface $symfonyTransport, Envelope $envelope): self
    {
        return new self(false, $symfonyTransport, $envelope);
    }

    /**
     * @inheritDoc
     */
    public function isAutoAck(): bool
    {
        return $this->isAutoAck;
    }

    /**
     * @inheritDoc
     */
    public function disableAutoAck(): void
    {
        $this->isAutoAck = false;
    }

    /**
     * @inheritDoc
     */
    public function accept(): void
    {
        $this->symfonyTransport->ack($this->envelope);
    }

    /**
     * @inheritDoc
     */
    public function reject(): void
    {
        $this->symfonyTransport->reject($this->envelope);
    }

    /**
     * @inheritDoc
     */
    public function requeue(): void
    {
        $this->symfonyTransport->send($this->envelope);
        $this->symfonyTransport->reject($this->envelope);
    }
}
