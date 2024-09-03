<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Outbound;

use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Kafka\Configuration\KafkaAdmin;
use Ecotone\Messaging\Channel\PollableChannel\Serialization\OutboundMessageConverter;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHandler;
use Interop\Queue\Destination;

final class KafkaOutboundChannelAdapter implements MessageHandler
{


    public function __construct(
        private string $topicName,
        private KafkaAdmin $kafkaAdmin,
        protected bool                     $autoDeclare,
        protected OutboundMessageConverter $outboundMessageConverter,
        private ConversionService $conversionService
    ) {
    }

    /**
     * Handles given message
     */
    public function handle(Message $message): void
    {

    }
}