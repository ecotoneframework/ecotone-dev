<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\Collector;

use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\Support\MessageBuilder;

final class CollectorSenderInterceptor
{
    public function __construct(private Collector $collector, private string $targetChannel)
    {
    }

    public function send(MethodInvocation $methodInvocation, #[Reference] ConfiguredMessagingSystem $configuredMessagingSystem): mixed
    {
        $this->collector->enable();
        try {
            $result = $methodInvocation->proceed();
            $collectedMessages = $this->collector->getCollectedMessages();
            $this->collector->disable();
            if ($collectedMessages !== []) {
                $this->getTargetChannel($configuredMessagingSystem)->send(
                    MessageBuilder::withPayload($collectedMessages)->build()
                );
            }
        } finally {
            $this->collector->disable();
        }

        return $result;
    }

    private function getTargetChannel(ConfiguredMessagingSystem $configuredMessagingSystem): MessageChannel
    {
        return $configuredMessagingSystem->getMessageChannelByName($this->targetChannel);
    }
}