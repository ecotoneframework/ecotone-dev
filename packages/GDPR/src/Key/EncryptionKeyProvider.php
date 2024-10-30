<?php

declare(strict_types=1);

namespace Ecotone\GDPR\Key;

use Defuse\Crypto\Key;

interface EncryptionKeyProvider
{
    public function key(): Key;
}
