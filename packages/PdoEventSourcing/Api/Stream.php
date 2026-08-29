<?php

namespace Ecotone\Api\EventSourcing;

use Attribute;
use Ecotone\Api\Dbal\DbalConnectionReference;
use Ecotone\Messaging\Support\Assert;

use function sha1;

#[Attribute(Attribute::TARGET_CLASS)]
/**
 * licence Apache-2.0
 */
class Stream
{
    public function __construct(
        private ?string $name = null,
        private ?string $legacyStreamName = null,
        private string $connectionReferenceName = DbalConnectionReference::DEFAULT,
    ) {
        Assert::isTrue(
            $name !== null || $legacyStreamName !== null,
            'Stream attribute requires either a name or a legacyStreamName'
        );
        Assert::isTrue($name !== '', "Stream name can't be empty");
        Assert::isTrue($legacyStreamName !== '', "Legacy stream name can't be empty");
    }

    public function getName(): string
    {
        return $this->name ?? $this->legacyStreamName;
    }

    public function getTableName(): string
    {
        if ($this->legacyStreamName !== null) {
            return '_' . sha1($this->legacyStreamName);
        }

        return $this->name;
    }

    public function getConnectionReferenceName(): string
    {
        return $this->connectionReferenceName;
    }
}
