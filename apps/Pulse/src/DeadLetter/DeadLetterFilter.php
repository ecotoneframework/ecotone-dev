<?php

declare(strict_types=1);

namespace App\DeadLetter;

use App\Snapshot\ServiceScope;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use Symfony\Component\HttpFoundation\InputBag;

final readonly class DeadLetterFilter
{
    public const DATE_FIELDS = ['failedFrom', 'failedTo'];
    public const FIELDS = ['service', 'messageName', 'exceptionClass', 'failureId', 'failedFrom', 'failedTo', 'search'];

    public function __construct(
        public string $service,
        public string $messageName,
        public string $exceptionClass,
        public string $failureId,
        public string $failedFrom,
        public string $failedTo,
        public string $search,
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
            'failureId' => $this->failureId,
            'failedFrom' => self::atom($this->failedFrom),
            'failedTo' => self::atom($this->failedTo),
        ], static fn (?string $value) => $value !== null && $value !== '');

        return $conditions;
    }

    public function hasSearch(): bool
    {
        return $this->search !== '';
    }

    public function narrowsEntries(): bool
    {
        return $this->failedFrom !== '' || $this->failedTo !== '' || $this->search !== '';
    }

    /**
     * @param array<string, mixed> $group
     */
    public function selectsFailure(array $group): bool
    {
        return ($this->messageName === '' || $this->messageName === ($group['messageName'] ?? null))
            && ($this->exceptionClass === '' || $this->exceptionClass === ($group['exceptionClass'] ?? null))
            && ($this->failureId === '' || $this->failureId === ($group['failureId'] ?? null));
    }

    public function selectsService(string $service): bool
    {
        return $this->service === '' || $this->service === $service || ServiceScope::fromKey($service)->service === $this->service;
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function matches(array $entry): bool
    {
        $failedAt = new DateTimeImmutable((string) ($entry['failedAt'] ?? '@0'));
        $from = self::moment($this->failedFrom);
        $to = self::moment($this->failedTo);

        return ($this->messageName === '' || $this->messageName === ($entry['messageName'] ?? null))
            && ($this->exceptionClass === '' || $this->exceptionClass === ($entry['exceptionClass'] ?? null))
            && ($this->failureId === '' || $this->failureId === ($entry['failureId'] ?? null))
            && ($from === null || $failedAt >= $from)
            && ($to === null || $failedAt <= $to)
            && ($this->search === '' || $this->searchMatches($entry));
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function searchMatches(array $entry): bool
    {
        foreach (['messageId', 'payload'] as $field) {
            if (is_string($entry[$field] ?? null) && stripos($entry[$field], $this->search) !== false) {
                return true;
            }
        }

        return false;
    }

    public function invalidDateField(): ?string
    {
        foreach (self::DATE_FIELDS as $field) {
            if ($this->{$field} !== '' && self::moment($this->{$field}) === null) {
                return $field;
            }
        }

        return null;
    }

    private static function atom(string $value): ?string
    {
        return self::moment($value)?->format(DateTimeInterface::ATOM);
    }

    private static function moment(string $value): ?DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})?)?$/', $value) !== 1) {
            return null;
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }
    }
}
