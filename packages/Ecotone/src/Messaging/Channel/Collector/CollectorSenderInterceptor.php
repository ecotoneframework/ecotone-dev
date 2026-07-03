<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\Collector;

use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Messaging\Attribute\WithoutMessageCollector;
use Ecotone\Messaging\BatchMessage;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\Support\MessageBuilder;

/**
 * licence Apache-2.0
 */
final class CollectorSenderInterceptor
{
    public function __construct(private CollectorStorage $collectorStorage, private string $targetChannel)
    {
    }

    public function send(
        MethodInvocation $methodInvocation,
        Message $message,
        #[Reference] ConfiguredMessagingSystem $configuredMessagingSystem,
        #[Reference] LoggingGateway $logger,
        ?WithoutMessageCollector $withoutMessageCollector = null,
    ): mixed {
        if ($withoutMessageCollector !== null) {
            return $methodInvocation->proceed();
        }
        /** For example Command Bus inside Command Bus */
        if ($this->collectorStorage->isEnabled()) {
            return $methodInvocation->proceed();
        }

        $this->collectorStorage->enable();
        try {
            $result = $methodInvocation->proceed();
            $collectedMessages = $this->collectorStorage->releaseMessages($logger, $message);
            if ($collectedMessages !== []) {
                $this->getTargetChannel($configuredMessagingSystem)->send(
                    MessageBuilder::withPayload($this->combineIntoBatch($collectedMessages))->build()
                );
            }
        } finally {
            $this->collectorStorage->disable();
        }

        return $result;
    }

    private function getTargetChannel(ConfiguredMessagingSystem $configuredMessagingSystem): MessageChannel
    {
        return $configuredMessagingSystem->getMessageChannelByName($this->targetChannel);
    }

    /**
     * @param Message[] $collectedMessages
     */
    private function combineIntoBatch(array $collectedMessages): BatchMessage
    {
        $batchMessage = BatchMessage::constructEmpty();
        foreach ($collectedMessages as $collectedMessage) {
            $batchMessage = $batchMessage->append(
                $collectedMessage->getPayload(),
                $collectedMessage->getHeaders()->headers()
            );
        }

        return $batchMessage;
    }
}
