<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Closure;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Tracing\SpanAttribute;
use Ecotone\Messaging\Gateway\MessagingEntrypointService;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\Gathering\GatheringHeaders;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Modelling\MessageHandling\MetadataPropagator\MessageHeadersPropagatorInterceptor;
use Ecotone\Tracing\Tracer;
use Psr\Container\ContainerInterface;
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
        private ContainerInterface $container,
    ) {
        parent::__construct(channelResolver: $channelResolver, messageHeadersPropagator: $headersPropagator, messageBuilder: $messageBuilder);
    }

    public function send(mixed $payload, string $targetChannel): mixed
    {
        return $this->recorded(targetChannel: $targetChannel, hints: [], send: fn () => parent::send($payload, $targetChannel));
    }

    public function sendWithHeaders(mixed $payload, array $headers, string $targetChannel, ?string $routingSlip = null): mixed
    {
        return $this->recorded(targetChannel: $targetChannel, hints: [...self::slipOf($routingSlip), ...self::gatheringOf($headers)], send: fn () => parent::sendWithHeaders($payload, $headers, $targetChannel, $routingSlip));
    }

    public function sendWithHeadersWithMessageReply(mixed $payload, array $headers, string $targetChannel, ?string $routingSlip = null): ?Message
    {
        return $this->recorded(targetChannel: $targetChannel, hints: [...self::slipOf($routingSlip), ...self::gatheringOf($headers)], send: fn () => parent::sendWithHeadersWithMessageReply($payload, $headers, $targetChannel, $routingSlip));
    }

    public function sendWithHeadersPropagation(mixed $payload, array $headers, string $targetChannel, ?string $routingSlip = null): mixed
    {
        return $this->recorded(targetChannel: $targetChannel, hints: [...self::slipOf($routingSlip), ...self::gatheringOf($headers), $this->propagatedOver($headers)], send: fn () => parent::sendWithHeadersPropagation($payload, $headers, $targetChannel, $routingSlip));
    }

    public function sendWithHeadersPropagationAndMessageReply(mixed $payload, array $headers, string $targetChannel, ?string $routingSlip = null): ?Message
    {
        return $this->recorded(targetChannel: $targetChannel, hints: [...self::slipOf($routingSlip), ...self::gatheringOf($headers), $this->propagatedOver($headers)], send: fn () => parent::sendWithHeadersPropagationAndMessageReply($payload, $headers, $targetChannel, $routingSlip));
    }

    public function sendMessage(Message $message): mixed
    {
        $headers = $message->getHeaders();

        return $this->recorded(targetChannel: $headers->containsKey(self::ENTRYPOINT) ? (string) $headers->get(self::ENTRYPOINT) : 'the channel its message names', hints: [], send: fn () => parent::sendMessage($message));
    }

    /**
     * @return list<ExecutionHint>
     */
    private static function slipOf(?string $routingSlip): array
    {
        return $routingSlip === null || $routingSlip === '' ? [] : [ExecutionHint::shownInLines(ExecutionHint::ROUTING_SLIP, 'adds ' . str_replace(',', ', ', $routingSlip))];
    }

    /**
     * @param array<string, mixed> $headers
     * @return list<ExecutionHint>
     */
    private static function gatheringOf(array $headers): array
    {
        if (! isset($headers[GatheringHeaders::GROUP_ID])) {
            return [];
        }

        return [ExecutionHint::shownInLines(ExecutionHint::GROUP, 'part ' . ($headers[GatheringHeaders::PART_NUMBER] ?? '?') . ' of ' . ($headers[GatheringHeaders::SIZE] ?? '?') . ' of ' . $headers[GatheringHeaders::GROUP_ID] . ', reports to ' . ($headers[GatheringHeaders::CHANNEL_NAME] ?? '?'))];
    }

    /**
     * @param array<string, mixed> $headers
     */
    private function propagatedOver(array $headers): ExecutionHint
    {
        $propagated = array_keys(array_diff_key($this->headersPropagator->propagateHeaders($headers), $headers));

        return ExecutionHint::detail(ExecutionHint::PROPAGATING, $propagated === [] ? 'no headers' : implode(', ', $propagated));
    }

    /**
     * @param list<ExecutionHint> $hints
     */
    private function recorded(string $targetChannel, array $hints, Closure $send): mixed
    {
        $tracer = $this->tracer();
        $step = $tracer->startEntry('entrypoint sends to ' . $targetChannel, attributes: $hints === [] ? [] : [SpanAttribute::EXECUTION_HINTS => ExecutionHint::encoded($hints)]);
        try {
            $result = $send();
        } catch (Throwable $failure) {
            if ($step !== null) {
                $tracer->failStep($step, $failure);
            }

            throw $failure;
        }
        if ($step !== null) {
            $tracer->endStep($step, '');
        }

        return $result;
    }

    private function tracer(): ExecutionRecordingTracer
    {
        return $this->container->get(Tracer::class);
    }
}
