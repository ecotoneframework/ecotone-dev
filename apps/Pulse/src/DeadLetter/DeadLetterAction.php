<?php

declare(strict_types=1);

namespace App\DeadLetter;

final readonly class DeadLetterAction
{
    private const REPLAY_ROUTING_KEY = 'ecotone.pulse.dead_letter.replay';
    private const DELETE_ROUTING_KEY = 'ecotone.pulse.dead_letter.delete';

    private function __construct(
        public string $routingKey,
        public string $payload,
    ) {
    }

    public function isReplay(): bool
    {
        return $this->routingKey === self::REPLAY_ROUTING_KEY;
    }

    /**
     * @param list<string> $messageIds
     */
    public static function replayIds(array $messageIds, string $targetChannel): self
    {
        return new self(self::REPLAY_ROUTING_KEY, self::json(['messageIds' => $messageIds] + self::target($targetChannel)));
    }

    /**
     * @param array<string, string> $conditions
     */
    public static function replayMatching(array $conditions, string $targetChannel): self
    {
        return new self(self::REPLAY_ROUTING_KEY, self::json(['filter' => $conditions] + self::target($targetChannel)));
    }

    /**
     * @param list<string> $messageIds
     */
    public static function deleteIds(array $messageIds): self
    {
        return new self(self::DELETE_ROUTING_KEY, self::json(['messageIds' => $messageIds]));
    }

    /**
     * @param array<string, string> $conditions
     */
    public static function deleteMatching(array $conditions): self
    {
        return new self(self::DELETE_ROUTING_KEY, self::json(['filter' => $conditions]));
    }

    /**
     * @return array{targetChannel?: string}
     */
    private static function target(string $targetChannel): array
    {
        return $targetChannel === '' ? [] : ['targetChannel' => $targetChannel];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function json(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR);
    }
}
