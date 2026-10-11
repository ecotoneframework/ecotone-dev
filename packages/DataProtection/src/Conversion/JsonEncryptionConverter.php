<?php

declare(strict_types=1);

namespace Ecotone\DataProtection\Conversion;

use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Messaging\Handler\Type;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
class JsonEncryptionConverter extends AbstractEncryptionConverter
{
    public function convert($source, Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType)
    {
        $data = json_decode($source, true);
        $data = $this->encrypt($data);

        return json_encode($data);
    }

    public function matches(Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType): bool
    {
        return $sourceType->acceptType($this->supportedType) && $targetType->isString() && $targetMediaType->isCompatibleWith(MediaType::createApplicationJson()) && $targetMediaType->hasParameter('encrypted');
    }
}
