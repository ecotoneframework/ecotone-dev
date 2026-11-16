<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

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
        $step = $this->tracer->startEntry($this->entry, continuesAReceive: true);
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
