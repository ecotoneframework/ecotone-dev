<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Messaging\Handler\Gateway\Gateway;
use Ecotone\Messaging\Handler\Gateway\GatewayReplyConverter;
use Ecotone\Messaging\Handler\Gateway\MethodCallToMessageConverter;
use Ecotone\Messaging\Handler\MessageProcessor;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Support\MessageBuilder;
use Throwable;

/**
 * licence Apache-2.0
 */
final class RecordedGateway extends Gateway
{
    public function __construct(
        MethodCallToMessageConverter $methodCallToMessageConverter,
        ?Type $returnType,
        GatewayReplyConverter $gatewayReplyConverter,
        MessageProcessor $gatewayInternalProcessor,
        MessageBuilder $messageBuilder,
        private string $entry,
        private ExecutionRecordingTracer $tracer,
    ) {
        parent::__construct($methodCallToMessageConverter, $returnType, $gatewayReplyConverter, $gatewayInternalProcessor, $messageBuilder);
    }

    public function execute(array $methodArgumentValues)
    {
        $handled = $methodArgumentValues[0] ?? null;
        $step = $this->tracer->startGatewayEntry($this->entry, $handled instanceof Message ? (string) $handled->getHeaders()->get(MessageHeaders::MESSAGE_ID) : null);
        try {
            $result = parent::execute($methodArgumentValues);
        } catch (Throwable $failure) {
            if ($step !== null) {
                $this->tracer->failStep($step, $failure);
            }

            throw $failure;
        }
        if ($step !== null) {
            $this->tracer->endStep($step, ExecutionRecorder::outcomeOf($result));
        }

        return $result;
    }
}
