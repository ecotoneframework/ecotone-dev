<?php

declare(strict_types=1);

namespace App\DeadLetter;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Symfony\Component\HttpFoundation\InputBag;

final readonly class DeadLetterFilter
{
    public const DATE_FIELDS = ['failedFrom', 'failedTo'];
    public const FIELDS = ['service', 'messageName', 'exceptionClass', 'failedFrom', 'failedTo'];

    public function __construct(
        public string $service,
        public string $messageName,
        public string $exceptionClass,
        public string $failedFrom,
        public string $failedTo,
    ) {
    }

    /**
     * @param InputBag<string> $input
     */
    public static function fromInput(InputBag $input): self
    {
        return new self(...array_map(static fn (string $field) => trim((string) $input->get($field, '')), array_combine(self::FIELDS, self::FIELDS)));
    }

    /**
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter(get_object_vars($this), static fn (string $value) => $value !== '');
    }

    public function hasStoredConditions(): bool
    {
        return $this->storedConditions() !== [];
    }

    /**
     * @return array<string, string>
     */
    public function storedConditions(): array
    {
        $conditions = array_filter([
            'messageName' => $this->messageName,
            'exceptionClass' => $this->exceptionClass,
            'failedFrom' => self::atom($this->failedFrom),
            'failedTo' => self::atom($this->failedTo),
        ], static fn (?string $value) => $value !== null && $value !== '');

        return $conditions;
    }

    public function selectsService(string $service): bool
    {
        return $this->service === '' || $this->service === $service;
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function matches(array $entry): bool
    {
        $failedAt = new DateTimeImmutable((string) ($entry['failedAt'] ?? '@0'));

        return ($this->messageName === '' || $this->messageName === ($entry['messageName'] ?? null))
            && ($this->exceptionClass === '' || $this->exceptionClass === ($entry['exceptionClass'] ?? null))
            && ($this->failedFrom === '' || $failedAt >= self::moment($this->failedFrom))
            && ($this->failedTo === '' || $failedAt <= self::moment($this->failedTo));
    }

    private static function atom(string $value): ?string
    {
        return $value === '' ? null : self::moment($value)->format(DateTimeInterface::ATOM);
    }

    private static function moment(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
