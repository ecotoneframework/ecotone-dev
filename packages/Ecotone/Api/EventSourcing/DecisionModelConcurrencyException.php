<?php

declare(strict_types=1);

namespace Ecotone\Api\EventSourcing;

use Ecotone\EventSourcing\Tagging\AggregateCounterTag;
use Ecotone\Messaging\Support\ConcurrencyException;

use function implode;
use function sprintf;

/**
 * licence Enterprise
 */
class DecisionModelConcurrencyException extends ConcurrencyException
{
    public const LOG_MESSAGE = 'Dynamic Consistency Boundary conflict';

    public const CONFLICT_TAG_FIELD = 'ecotone.dcb.conflict.tag';

    public const CONFLICT_EXPECTED_VERSION_FIELD = 'ecotone.dcb.conflict.expected_version';

    public const CONFLICT_CURRENT_VERSION_FIELD = 'ecotone.dcb.conflict.current_version';

    public const CONFLICT_MODEL_FIELD = 'ecotone.dcb.conflict.model';

    private string $conflictingTagName = '';

    private string $conflictingTagValue = '';

    private int $expectedVersion = 0;

    private int $currentVersion = 0;

    /**
     * @var string[]
     */
    private array $decidedBy = [];

    /**
     * @param string[] $decidedBy the decision models, or the #[DecisionBoundary] method, scoped by the conflicting tag
     */
    public static function forConflict(string $tagName, string $tagValue, int $capturedVersion, int $currentVersion, array $decidedBy = []): self
    {
        $message = sprintf(
            'Concurrent append conflict on tag %s:%s (expected version %d, current version %d)',
            $tagName,
            $tagValue,
            $capturedVersion,
            $currentVersion,
        );

        if ($decidedBy !== []) {
            $message .= sprintf(' while deciding %s', implode(', ', $decidedBy));
        }

        $message .= '. The tag moved after it was read: another transaction committed to it, or an earlier append in the same '
            . 'transaction did (for example a command sent from inside the handler whose own handler appends to the same tag) '
            . '-- the latter fails on every retry, so decide both in one handler instead.';

        return self::describing(self::create($message), $tagName, $tagValue, $capturedVersion, $currentVersion, $decidedBy);
    }

    public static function forAggregateConflict(string $aggregateType, string $aggregateId, int $capturedVersion, int $currentVersion): self
    {
        $exception = self::create(sprintf(
            '%s %s changed since it was loaded (it was at change %d when read, it is at change %d now): another transaction saved it, '
            . 'or an earlier save in the same transaction did (for example a command sent from inside the handler whose own handler saves the same aggregate) '
            . '-- the latter fails on every retry, so decide both in one handler instead.',
            $aggregateType,
            $aggregateId,
            $capturedVersion,
            $currentVersion,
        ));

        return self::describing($exception, AggregateCounterTag::nameFor($aggregateType), $aggregateId, $capturedVersion, $currentVersion, []);
    }

    /**
     * @return array<string, string|int>
     */
    public function conflictFields(): array
    {
        return [
            self::CONFLICT_TAG_FIELD => $this->conflictingTagName . ':' . $this->conflictingTagValue,
            self::CONFLICT_EXPECTED_VERSION_FIELD => $this->expectedVersion,
            self::CONFLICT_CURRENT_VERSION_FIELD => $this->currentVersion,
            self::CONFLICT_MODEL_FIELD => implode(', ', $this->decidedBy),
        ];
    }

    public function conflictingTagName(): string
    {
        return $this->conflictingTagName;
    }

    public function conflictingTagValue(): string
    {
        return $this->conflictingTagValue;
    }

    public function expectedVersion(): int
    {
        return $this->expectedVersion;
    }

    public function currentVersion(): int
    {
        return $this->currentVersion;
    }

    /**
     * @return string[]
     */
    public function decidedBy(): array
    {
        return $this->decidedBy;
    }

    /**
     * @param string[] $decidedBy
     */
    private static function describing(self $exception, string $tagName, string $tagValue, int $expectedVersion, int $currentVersion, array $decidedBy): self
    {
        $exception->conflictingTagName = $tagName;
        $exception->conflictingTagValue = $tagValue;
        $exception->expectedVersion = $expectedVersion;
        $exception->currentVersion = $currentVersion;
        $exception->decidedBy = $decidedBy;

        return $exception;
    }
}
