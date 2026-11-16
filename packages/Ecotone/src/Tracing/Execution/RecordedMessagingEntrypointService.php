<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Closure;
use Ecotone\Api\Messaging\Message;
use Ecotone\Messaging\Gateway\MessagingEntrypointService;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Modelling\MessageHandling\MetadataPropagator\MessageHeadersPropagatorInterceptor;
use Throwable;

/**
 * licence Apache-2.0
 */
final class RecordedMessagingEntrypointService extends MessagingEntrypointService
{
    public function __construct(
        ChannelResolver $channelResolver,
        private MessageHeadersPropagatorInterceptor $headersPropagator,
        MessageBuilder $messageBuilder,
        private ExecutionRecordingTracer $tracer,
    ) {
        parent::__construct($channelResolver, $headersPropagator, $messageBuilder);
    }

    public function send(mixed $payload, string $targetChannel): mixed
    {
        return $this->recorded($targetChannel, '', fn () => parent::send($payload, $targetChannel));
    }

    public function sendWithHeaders(mixed $payload, array $headers, string $targetChannel, ?string $routingSlip = null): mixed
    {
        return $this->recorded($targetChannel, self::slipOf($routingSlip), fn () => parent::sendWithHeaders($payload, $headers, $targetChannel, $routingSlip));
    }

    public function sendWithHeadersWithMessageReply(mixed $payload, array $headers, string $targetChannel, ?string $routingSlip = null): ?Message
    {
        return $this->recorded($targetChannel, self::slipOf($routingSlip), fn () => parent::sendWithHeadersWithMessageReply($payload, $headers, $targetChannel, $routingSlip));
    }

    public function sendWithHeadersPropagation(mixed $payload, array $headers, string $targetChannel, ?string $routingSlip = null): mixed
    {
        return $this->recorded($targetChannel, self::slipOf($routingSlip) . $this->propagatedOver($headers), fn () => parent::sendWithHeadersPropagation($payload, $headers, $targetChannel, $routingSlip));
    }

    public function sendWithHeadersPropagationAndMessageReply(mixed $payload, array $headers, string $targetChannel, ?string $routingSlip = null): ?Message
    {
        return $this->recorded($targetChannel, self::slipOf($routingSlip) . $this->propagatedOver($headers), fn () => parent::sendWithHeadersPropagationAndMessageReply($payload, $headers, $targetChannel, $routingSlip));
    }

    public function sendMessage(Message $message): mixed
    {
        $headers = $message->getHeaders();

        return $this->recorded($headers->containsKey(self::ENTRYPOINT) ? (string) $headers->get(self::ENTRYPOINT) : 'the channel its message names', '', fn () => parent::sendMessage($message));
    }

    private static function slipOf(?string $routingSlip): string
    {
        return $routingSlip === null || $routingSlip === '' ? '' : ', setting the routing slip ' . str_replace(',', ', ', $routingSlip);
    }

    /**
     * @param array<string, mixed> $headers
     */
    private function propagatedOver(array $headers): string
    {
        $propagated = array_keys(array_diff_key($this->headersPropagator->propagateHeaders($headers), $headers));

        return ', propagating ' . ($propagated === [] ? 'no headers' : implode(', ', $propagated));
    }

    private function recorded(string $targetChannel, string $propagation, Closure $send): mixed
    {
        $step = $this->tracer->startEntry('entrypoint sends to ' . $targetChannel . $propagation);
        try {
            $result = $send();
        } catch (Throwable $failure) {
            if ($step !== null) {
                $this->tracer->failStep($step, $failure);
            }

            throw $failure;
        }
        if ($step !== null) {
            $this->tracer->endStep($step, '');
        }

        return $result;
    }
}
