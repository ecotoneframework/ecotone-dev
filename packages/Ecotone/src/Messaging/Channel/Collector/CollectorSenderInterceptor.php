<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\Collector;

use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Handler\MessageHandlingException;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplate;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\Support\ErrorMessage;
use Ecotone\Messaging\Support\MessageBuilder;
use Psr\Log\LoggerInterface;

final class CollectorSenderInterceptor
{
    public function __construct(private CollectorStorage $collectorStorage, private string $targetChannel)
    {
    }

    public function send(MethodInvocation $methodInvocation, #[Reference] ConfiguredMessagingSystem $configuredMessagingSystem, #[Reference("logger")] LoggerInterface $logger): mixed
    {
        /** For example Command Bus inside Command Bus */
        if ($this->collectorStorage->isEnabled()) {
            return $methodInvocation->proceed();
        }

        $this->collectorStorage->enable();
        try {
            $result = $methodInvocation->proceed();
            $collectedMessages = $this->collectorStorage->getCollectedMessages();
            $this->collectorStorage->disable();
            if ($collectedMessages !== []) {
                $messageChannel = $this->getTargetChannel($configuredMessagingSystem);

                foreach ($collectedMessages as $collectedMessage) {
                    $messageChannel->send($collectedMessage);
                }
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
}