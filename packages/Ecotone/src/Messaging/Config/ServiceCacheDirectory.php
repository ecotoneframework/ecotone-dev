<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config;

/**
 * ServiceConfiguration can be dumped for given environment and then code can be moved/symlinked.
 * So cache directory have to be split from dumped configuration.
 * This class promotes design where cache is resolved during execution phase.
 */
final class ServiceCacheDirectory
{
    public const REFERENCE_NAME = self::class;

    public function __construct(private string $path) {}

    public static function create(string $path): self
    {
        return new self($path);
    }

    public static function withSystemTempDirectory(): self
    {
        return new self(sys_get_temp_dir());
    }

    public function getPath(): string
    {
        return $this->path;
    }
}