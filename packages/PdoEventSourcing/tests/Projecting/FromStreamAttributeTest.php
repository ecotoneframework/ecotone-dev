<?php
/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Tests\Projecting;

use Ecotone\EventSourcing\Attribute\FromStream;
use PHPUnit\Framework\TestCase;

final class FromStreamAttributeTest extends TestCase
{
    public function test_it_accepts_single_stream(): void
    {
        $attr = new FromStream('orders');
        self::assertSame(['orders'], $attr->getStreams());
        self::assertSame('orders', $attr->getStream());
        self::assertFalse($attr->isMultiStream());
    }

    public function test_it_accepts_multiple_streams(): void
    {
        $attr = new FromStream(['orders', 'invoices']);
        self::assertSame(['orders', 'invoices'], $attr->getStreams());
        self::assertTrue($attr->isMultiStream());
        self::assertSame('orders', $attr->getStream(), 'First stream should be returned by getStream for BC');
    }
}
