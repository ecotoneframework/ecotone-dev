<?php

namespace Ecotone\PHPEncryption;

/**
 * licence Apache-2.0
 */
final readonly class DerivedKeys
{
    public function __construct(public string $authenticationKey = '', public string $encryptionKey = '')
    {
    }
}
