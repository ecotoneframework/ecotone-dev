<?php

declare(strict_types=1);

namespace Test\Ecotone\DataProtection\Unit;

use Ecotone\Api\DataProtection\DataProtectionConfiguration;
use Ecotone\Api\DataProtection\Key;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class DataProtectionConfigurationCopyTest extends TestCase
{
    public function test_adding_a_key_changes_only_the_keys_and_leaves_the_original_untouched(): void
    {
        $primaryKey = Key::createNewRandomKey();
        $secondaryKey = Key::createNewRandomKey();
        $original = DataProtectionConfiguration::create('primary', $primaryKey);
        $fieldsBefore = get_object_vars($original);

        $copy = $original->withKey('secondary', $secondaryKey);

        self::assertSame(['keys', 'defaultKey'], array_keys($fieldsBefore));
        self::assertSame($fieldsBefore, get_object_vars($original));
        self::assertSame(['primary' => $primaryKey, 'secondary' => $secondaryKey], $copy->keys);
        self::assertSame('primary', $copy->defaultKey);
    }

    public function test_adding_a_default_key_changes_the_keys_and_the_default_and_leaves_the_original_untouched(): void
    {
        $primaryKey = Key::createNewRandomKey();
        $secondaryKey = Key::createNewRandomKey();
        $original = DataProtectionConfiguration::create('primary', $primaryKey);
        $fieldsBefore = get_object_vars($original);

        $copy = $original->withKey('secondary', $secondaryKey, asDefault: true);

        self::assertSame($fieldsBefore, get_object_vars($original));
        self::assertSame(['primary' => $primaryKey, 'secondary' => $secondaryKey], $copy->keys);
        self::assertSame('secondary', $copy->defaultKey);
    }
}
