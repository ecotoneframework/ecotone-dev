<?php

declare(strict_types=1);

namespace App\DeadLetter;

use Symfony\Component\HttpFoundation\InputBag;

final readonly class DeadLetterNotice
{
    public const ACTION_SENT = 'action_sent';
    public const SEND_FAILED = 'send_failed';
    public const NOTHING_SELECTED = 'nothing_selected';
    public const NO_FILTER_CONDITION = 'no_filter_condition';
    public const UNKNOWN_SERVICE = 'unknown_service';
    public const NOT_CONFIGURED = 'not_configured';
    public const NO_DEAD_LETTERS_ANYWHERE = 'no_dead_letters_anywhere';
    public const INVALID_DATE = 'invalid_date';
    public const UNKNOWN_CHANNEL = 'unknown_channel';
    public const TARGET_NEEDS_SERVICE = 'target_needs_service';
    public const TARGET_PER_SERVICE = 'target_per_service';

    private const CODES = [
        self::ACTION_SENT, self::SEND_FAILED, self::NOTHING_SELECTED, self::NO_FILTER_CONDITION, self::UNKNOWN_SERVICE,
        self::NOT_CONFIGURED, self::NO_DEAD_LETTERS_ANYWHERE, self::INVALID_DATE, self::UNKNOWN_CHANNEL, self::TARGET_NEEDS_SERVICE, self::TARGET_PER_SERVICE,
    ];

    /**
     * @param array<string, string> $parameters
     */
    private function __construct(
        public string $code,
        public array $parameters,
    ) {
    }

    /**
     * @param list<string> $services
     */
    public static function actionSent(string $action, array $services): self
    {
        return new self(self::ACTION_SENT, ['action' => $action, 'services' => implode(',', $services)]);
    }

    /**
     * @param list<string> $services
     */
    public static function sendFailed(array $services): self
    {
        return new self(self::SEND_FAILED, ['services' => implode(',', $services)]);
    }

    public static function nothingSelected(): self
    {
        return new self(self::NOTHING_SELECTED, []);
    }

    public static function noFilterCondition(): self
    {
        return new self(self::NO_FILTER_CONDITION, []);
    }

    public static function unknownService(): self
    {
        return new self(self::UNKNOWN_SERVICE, []);
    }

    public static function notConfigured(string $service): self
    {
        return new self(self::NOT_CONFIGURED, ['service' => $service]);
    }

    public static function noDeadLettersAnywhere(): self
    {
        return new self(self::NO_DEAD_LETTERS_ANYWHERE, []);
    }

    public static function invalidDate(string $field): self
    {
        return new self(self::INVALID_DATE, ['field' => $field]);
    }

    public static function unknownChannel(string $service, string $channel): self
    {
        return new self(self::UNKNOWN_CHANNEL, ['service' => $service, 'channel' => $channel]);
    }

    public static function targetPerService(): self
    {
        return new self(self::TARGET_PER_SERVICE, []);
    }

    public static function targetNeedsService(): self
    {
        return new self(self::TARGET_NEEDS_SERVICE, []);
    }

    /**
     * @param InputBag<string> $query
     * @param list<string> $configuredServices
     */
    public static function fromInput(InputBag $query, array $configuredServices): ?self
    {
        $code = (string) $query->get('notice', '');
        if (! in_array($code, self::CODES, true)) {
            return null;
        }

        $service = (string) $query->get('service', '');
        $services = array_values(array_filter(explode(',', (string) $query->get('services', '')), static fn (string $name) => in_array($name, $configuredServices, true)));
        $action = (string) $query->get('action', '');
        $field = (string) $query->get('field', '');
        $channel = (string) $query->get('channel', '');

        return match ($code) {
            self::ACTION_SENT => in_array($action, ['replay', 'delete'], true) && $services !== [] ? self::actionSent($action, $services) : null,
            self::SEND_FAILED => $services !== [] ? self::sendFailed($services) : null,
            self::NOT_CONFIGURED => in_array($service, $configuredServices, true) ? self::notConfigured($service) : null,
            self::UNKNOWN_CHANNEL => in_array($service, $configuredServices, true) && $channel !== '' ? self::unknownChannel($service, $channel) : null,
            self::INVALID_DATE => in_array($field, DeadLetterFilter::DATE_FIELDS, true) ? self::invalidDate($field) : null,
            default => new self($code, []),
        };
    }

    /**
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return ['notice' => $this->code] + $this->parameters;
    }

    public function isError(): bool
    {
        return $this->code !== self::ACTION_SENT;
    }

    /**
     * @return list<string>
     */
    public function services(): array
    {
        return array_values(array_filter(explode(',', $this->parameters['services'] ?? '')));
    }
}
