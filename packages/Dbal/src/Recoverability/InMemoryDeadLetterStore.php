<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Recoverability;

use function array_keys;
use function array_map;
use function array_merge;
use function array_slice;
use function count;

use Ecotone\Api\Messaging\ErrorContext;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\MessageConverter\HeaderMapper;
use Ecotone\Messaging\Support\MessageBuilder;

use function json_decode;
use function json_encode;

use Symfony\Component\Uid\Uuid;

use function usort;

/**
 * licence Apache-2.0
 */
final class InMemoryDeadLetterStore implements DeadLetterStore
{
    /**
     * @var array<string, array{payload: mixed, headers: array<string, mixed>, failedAt: int}>
     */
    private array $messages = [];

    public function __construct(
        private HeaderMapper $headerMapper,
        private ConversionService $conversionService,
    ) {
    }

    public function store(Message $message): void
    {
        $headers = $message->getHeaders()->headers();
        $messageId = (string) $headers[MessageHeaders::MESSAGE_ID];
        if (isset($this->messages[$messageId])) {
            $messageId = Uuid::v7()->toRfc4122();
        }

        $this->messages[$messageId] = [
            'payload' => $message->getPayload(),
            'headers' => json_decode(json_encode($this->headerMapper->mapFromMessageHeaders($headers, $this->conversionService), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE), true, 512, JSON_THROW_ON_ERROR),
            'failedAt' => (int) $headers[MessageHeaders::TIMESTAMP],
        ];
    }

    public function list(int $limit, int $offset): array
    {
        $messageIds = array_map('strval', array_keys($this->messages));
        usort($messageIds, fn (string $first, string $second): int => $this->messages[$second]['failedAt'] <=> $this->messages[$first]['failedAt']);

        return array_map(
            fn (string $messageId): ErrorContext => ErrorContext::fromHeaders(array_merge($this->messages[$messageId]['headers'], [MessageHeaders::MESSAGE_ID => $messageId])),
            array_slice($messageIds, $offset, $limit),
        );
    }

    public function find(string $messageId): ?Message
    {
        if (! isset($this->messages[$messageId])) {
            return null;
        }

        return MessageBuilder::withPayload($this->messages[$messageId]['payload'])
            ->setMultipleHeaders($this->messages[$messageId]['headers'])
            ->build();
    }

    public function count(): int
    {
        return count($this->messages);
    }

    public function delete(string $messageId): void
    {
        unset($this->messages[$messageId]);
    }

    public function deleteAll(): void
    {
        $this->messages = [];
    }
}
