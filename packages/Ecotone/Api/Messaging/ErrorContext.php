<?php

namespace Ecotone\Api\Messaging;

/**
 * licence Apache-2.0
 */
class ErrorContext
{
    public const WHOLE_ERROR_CONTEXT = [
        self::EXCEPTION_CLASS,
        self::EXCEPTION_STACKTRACE,
        self::EXCEPTION_FILE,
        self::EXCEPTION_LINE,
        self::EXCEPTION_CODE,
        self::EXCEPTION_MESSAGE,
    ];

    public const EXCEPTION = 'exception';
    public const EXCEPTION_CLASS = 'ecotone-class';
    public const EXCEPTION_STACKTRACE = 'exception-stacktrace';
    public const EXCEPTION_FILE = 'exception-file';
    public const EXCEPTION_LINE = 'exception-line';
    public const EXCEPTION_CODE = 'exception-code';
    public const EXCEPTION_MESSAGE = 'exception-message';
    public const DLQ_MESSAGE_REPLIED = 'ecotone.dlq.message_replied';

    private string $exceptionClass;
    private string $messageId;
    public readonly int $failedTimestamp;
    public readonly string $stackTrace;
    public readonly string $file;
    public readonly string $line;
    private string $code;
    private string $message;
    public readonly ?string $partitionTrackingName;
    public readonly ?string $partitionKey;
    public readonly ?int $partitionPosition;

    private function __construct(string $messageId, int $failedTimestamp, string $exceptionClass, string $message, string $stackTrace, string $code, string $file, string $line, ?string $partitionTrackingName, ?string $partitionKey, ?int $partitionPosition)
    {
        $this->messageId = $messageId;
        $this->failedTimestamp = $failedTimestamp;
        $this->exceptionClass = $exceptionClass;
        $this->stackTrace = $stackTrace;
        $this->file       = $file;
        $this->line       = $line;
        $this->code       = $code;
        $this->message    = $message;
        $this->partitionTrackingName = $partitionTrackingName;
        $this->partitionKey = $partitionKey;
        $this->partitionPosition = $partitionPosition;
    }

    public static function fromHeaders(array $messageHeaders): self
    {
        return new self(
            $messageHeaders[MessageHeaders::MESSAGE_ID],
            $messageHeaders[MessageHeaders::TIMESTAMP],
            $messageHeaders[self::EXCEPTION_CLASS] ?? '',
            $messageHeaders[self::EXCEPTION_MESSAGE],
            $messageHeaders[self::EXCEPTION_STACKTRACE],
            $messageHeaders[self::EXCEPTION_CODE],
            $messageHeaders[self::EXCEPTION_FILE],
            $messageHeaders[self::EXCEPTION_LINE],
            $messageHeaders[MessageHeaders::DEAD_LETTER_PARTITION_TRACKING_NAME] ?? null,
            $messageHeaders[MessageHeaders::DEAD_LETTER_PARTITION_KEY] ?? null,
            $messageHeaders[MessageHeaders::DEAD_LETTER_PARTITION_POSITION] ?? null,
        );
    }

    public function getMessageId(): string
    {
        return $this->messageId;
    }

    public function getExceptionClass(): string
    {
        return $this->exceptionClass;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function toArray(): array
    {
        return [
            self::EXCEPTION_CLASS => $this->exceptionClass,
            self::EXCEPTION_MESSAGE => $this->message,
            self::EXCEPTION_STACKTRACE => $this->stackTrace,
            self::EXCEPTION_FILE => $this->file,
            self::EXCEPTION_LINE => $this->line,
            self::EXCEPTION_CODE => $this->code,
        ];
    }
}
