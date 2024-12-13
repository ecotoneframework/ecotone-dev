<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcingV2\EventStore\SQL;

trait EnsureSchemaExistsTrait
{
    protected bool $schemaIsKnownToExists = false;

    protected function ensureSchemaExists()
    {
        if (!$this->schemaIsKnownToExists && $this->createSchema) {
            $this->schemaUp();
            $this->schemaIsKnownToExists = true;
        }
    }
}