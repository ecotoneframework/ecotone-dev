<?php

declare(strict_types=1);

namespace App\Ui;

final readonly class StackTrace
{
    public const TRUNCATION_MARKER = "\n... [truncated]";

    private const HANDLER_INVOKERS = ['AroundMethodInvocation.php', 'MethodInvoker.php'];
    private const VENDOR_DIRECTORY = '#(^|/)vendor/#';
    private const ECOTONE_PACKAGE_SOURCES = '#(^|/)packages/(Amqp|DataProtection|Dbal|Ecotone|Enqueue|JmsConverter|Kafka|Laravel|LiteApplication|OpenTelemetry|PdoEventSourcing|Redis|Sqs|Symfony|Tempest)/(src|Api)/#';

    /**
     * @param list<array{number: ?int, file: string, line: ?int, call: string, location: string, application: bool, thrownHere: bool}> $applicationFrames
     * @param list<array{number: ?int, file: string, line: ?int, call: string, location: string, application: bool, thrownHere: bool}> $frameworkFrames
     */
    private function __construct(public array $applicationFrames, public array $frameworkFrames, public bool $truncated)
    {
    }

    public static function of(?string $stackTrace, string $thrownInFile, int $thrownAtLine): self
    {
        $stackTrace ??= '';
        $truncated = str_ends_with($stackTrace, self::TRUNCATION_MARKER);
        if ($truncated) {
            $stackTrace = substr($stackTrace, 0, -strlen(self::TRUNCATION_MARKER));
        }

        $application = [];
        $framework = [];
        if ($thrownInFile !== '' && self::isApplicationFile($thrownInFile)) {
            $application[] = self::frame(null, $thrownInFile, $thrownAtLine, '', true);
        }
        foreach (preg_split('/\R/', trim($stackTrace)) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $frame = self::parsed($line);
            if ($frame['application']) {
                $application[] = $frame;
            } else {
                $framework[] = $frame;
            }
        }

        return new self($application, $framework, $truncated);
    }

    public function handlerMethod(): ?string
    {
        foreach ($this->frameworkFrames as $frame) {
            $invoker = basename(str_replace('\\', '/', $frame['file']));
            if (! in_array($invoker, self::HANDLER_INVOKERS, true) || preg_match('/^([A-Za-z_\\\\][\w\\\\]*)->(\w+)\(/', $frame['call'], $match) !== 1 || str_starts_with($match[1], 'Ecotone\\')) {
                continue;
            }

            return $match[1] . '::' . $match[2];
        }

        return null;
    }

    public function isEmpty(): bool
    {
        return $this->applicationFrames === [] && $this->frameworkFrames === [];
    }

    /**
     * @return array{number: ?int, file: string, line: ?int, call: string, location: string, application: bool, thrownHere: bool}
     */
    private static function parsed(string $line): array
    {
        if (preg_match('/^#(\d+)\s+(.+?)\((\d+)\):\s?(.*)$/', $line, $match) === 1) {
            return self::frame((int) $match[1], $match[2], (int) $match[3], $match[4], false);
        }
        if (preg_match('/^#(\d+)\s+(.*)$/', $line, $match) === 1) {
            return ['number' => (int) $match[1], 'file' => '', 'line' => null, 'call' => $match[2], 'location' => $match[2], 'application' => false, 'thrownHere' => false];
        }

        return ['number' => null, 'file' => '', 'line' => null, 'call' => $line, 'location' => $line, 'application' => false, 'thrownHere' => false];
    }

    /**
     * @return array{number: ?int, file: string, line: ?int, call: string, location: string, application: bool, thrownHere: bool}
     */
    private static function frame(?int $number, string $file, int $line, string $call, bool $thrownHere): array
    {
        return [
            'number' => $number,
            'file' => $file,
            'line' => $line,
            'call' => $call,
            'location' => basename(str_replace('\\', '/', $file)) . ':' . $line,
            'application' => self::isApplicationFile($file),
            'thrownHere' => $thrownHere,
        ];
    }

    private static function isApplicationFile(string $file): bool
    {
        $path = str_replace('\\', '/', $file);

        return preg_match(self::VENDOR_DIRECTORY, $path) !== 1 && preg_match(self::ECOTONE_PACKAGE_SOURCES, $path) !== 1;
    }
}
