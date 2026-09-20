<?php

declare(strict_types=1);

namespace Ecotone\Api\Dbal;

/**
 * licence Apache-2.0
 */
enum AutoCreateLevel
{
    case None;
    case CreateOnly;

    public function isCreateOnly(): bool
    {
        return $this === self::CreateOnly;
    }

    public static function fromBoolean(bool $isEnabled): self
    {
        return $isEnabled ? self::CreateOnly : self::None;
    }
}
