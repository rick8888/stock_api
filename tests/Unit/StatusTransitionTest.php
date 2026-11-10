<?php

namespace Tests\Unit;

use App\Exceptions\DomainException;
use App\Support\StatusTransition;
use PHPUnit\Framework\TestCase;

class StatusTransitionTest extends TestCase
{
    public function test_allows_valid_purchase_order_flow(): void
    {
        StatusTransition::assert('purchase_order', 'draft', 'sent');
        StatusTransition::assert('purchase_order', 'sent', 'confirmed');
        StatusTransition::assert('purchase_order', 'confirmed', 'received');
        $this->assertSame(
            ['partial', 'received', 'delayed', 'cancelled'],
            StatusTransition::allowed('purchase_order', 'confirmed'),
        );
    }

    public function test_rejects_illegal_shipment_jump(): void
    {
        $this->expectException(DomainException::class);
        StatusTransition::assert('shipment', 'done', 'in_transit', 'Expédition');
    }

    public function test_rejects_received_to_sent(): void
    {
        $this->expectException(DomainException::class);
        StatusTransition::assert('purchase_order', 'received', 'sent', 'OA');
    }

    public function test_same_status_is_noop(): void
    {
        StatusTransition::assert('route', 'done', 'done');
        $this->assertTrue(true);
    }

    public function test_rejects_delivery_delivered_to_failed(): void
    {
        $this->expectException(DomainException::class);
        StatusTransition::assert('delivery', 'delivered', 'failed');
    }
}
