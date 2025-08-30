<?php

declare(strict_types=1);

namespace Ecotone\SymfonyBundle\Messenger;

use Ecotone\Enqueue\EnqueueAcknowledgementCallback;
use Ecotone\Messaging\Endpoint\AcknowledgementCallback;
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Class EnqueueAcknowledgementCallback
 * @package Ecotone\Amqp
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
/**
 * licence Apache-2.0
 */
class SymfonyAcknowledgementCallback implements AcknowledgementCallback
{
    public const AUTO_ACK = 'auto';
    public const MANUAL_ACK = 'manual';
    public const NONE = 'none';

    private function __construct(
        private FinalFailureStrategy $failureStrategy,
        private bool $isAutoAcked,
        private TransportInterface   $symfonyTransport,
        private Envelope             $envelope
    ) {

    }

    public static function createWithAutoAck(TransportInterface $symfonyTransport, Envelope $envelope): self
    {
        return new self(FinalFailureStrategy::RESEND, true, $symfonyTransport, $envelope);
    }

    public static function createWithManualAck(TransportInterface $symfonyTransport, Envelope $envelope): self
    {
        return new self(FinalFailureStrategy::STOP, false, $symfonyTransport, $envelope);
    }

    /**
     * @param TransportInterface $symfonyTransport
     * @param Envelope $envelope
     * @param FinalFailureStrategy $finalFailureStrategy
     * @param bool $isAutoAcked
     * @return SymfonyAcknowledgementCallback
     */
    public static function createWithFailureStrategy(TransportInterface $symfonyTransport, Envelope $envelope, FinalFailureStrategy $finalFailureStrategy, bool $isAutoAcked): self
    {
        return new self($finalFailureStrategy, $isAutoAcked, $symfonyTransport, $envelope);
    }

    /**
     * @inheritDoc
     */
    public function getFailureStrategy(): FinalFailureStrategy
    {
        return $this->failureStrategy;
    }

    /**
     * @inheritDoc
     */
    public function isAutoAcked(): bool
    {
        return $this->isAutoAcked;
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
    public function resend(): void
    {
        $this->symfonyTransport->send($this->envelope);
        $this->symfonyTransport->reject($this->envelope);
    }

    /**
     * @inheritDoc
     */
    public function release(): void
    {
        $this->symfonyTransport->send($this->envelope);
        $this->symfonyTransport->reject($this->envelope);
    }
}
