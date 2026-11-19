<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Tests\Unit\Infra\Shopware\ResponseObject;

use PHPUnit\Framework\TestCase;
use Veliu\OrderPrinter\Infra\Shopware\ResponseObject\Order;
use Veliu\OrderPrinter\Infra\Shopware\ResponseObject\OrderAddress;
use Veliu\OrderPrinter\Infra\Shopware\ResponseObject\OrderLineItem;
use Veliu\OrderPrinter\Infra\Shopware\ResponseObject\ShippingMethod;

/** Text from the shop reaches the domain without printer control characters. */
final class ResponseObjectSanitizingTest extends TestCase
{
    public function testLineItemLabelAndProductNumber(): void
    {
        $item = OrderLineItem::fromArray([
            'id' => 'a',
            'parentId' => null,
            'label' => "Pizza\x1Bp\x00\x19\x19 Salami",
            'quantity' => 1,
            'totalPrice' => 9.5,
            'type' => 'container',
            'payload' => ['productNumber' => "26\x1D!\x11"],
        ]);

        self::assertSame('Pizza Salami', $item->label);
        self::assertSame('26', $item->productNumber);
    }

    public function testAddress(): void
    {
        $address = OrderAddress::fromArray([
            'firstName' => "Max\x1B@",
            'lastName' => "Muster\nmann",
            'street' => "Hauptstr. 1\x07",
            'city' => "12345 Musterstadt\x1BE\x01",
            'phoneNumber' => "\x1B@",
            'additionalAddressLine1' => "2. OG\r\nlinks",
        ]);

        self::assertSame('Max', $address->firstName);
        self::assertSame('Muster mann', $address->lastName);
        self::assertSame('Hauptstr. 1', $address->street);
        self::assertSame('12345 Musterstadt', $address->city);
        self::assertNull($address->phoneNumber);
        self::assertSame('2. OG links', $address->additionalAddressLine1);
    }

    public function testCustomerCommentKeepsLineBreaks(): void
    {
        $order = Order::fromArray([
            'id' => 'o',
            'orderNumber' => '10001',
            'customerComment' => "Bitte klingeln\r\n\x1D(k\x03\x00\x31\x50\x30bei Müller",
            'amountTotal' => 10,
            'lineItems' => [[
                'id' => 'a',
                'parentId' => null,
                'label' => 'Pizza',
                'quantity' => 1,
                'totalPrice' => 10,
                'type' => 'product',
                'payload' => ['productNumber' => '26'],
            ]],
            'stateMachineState' => ['technicalName' => 'open'],
            'createdAt' => '2026-01-01T12:00:00+00:00',
        ]);

        self::assertSame("Bitte klingeln\nk1P0bei Müller", $order->customerComment);
    }

    /** Pickup or delivery is only the plugin's custom field; a method without it delivers. */
    public function testShippingMethodFulfillment(): void
    {
        self::assertTrue(ShippingMethod::fromArray(['name' => 'Express', 'customFields' => ['shopbite_fulfillment' => 'pickup']])->isPickup);
        self::assertFalse(ShippingMethod::fromArray(['name' => 'Abholung', 'customFields' => ['shopbite_fulfillment' => 'delivery']])->isPickup);
        self::assertFalse(ShippingMethod::fromArray(['name' => 'Abholung', 'customFields' => null])->isPickup);
        self::assertFalse(ShippingMethod::fromArray(['name' => 'Abholung'])->isPickup);
        self::assertFalse(ShippingMethod::fromArray(['name' => 'Abholung', 'customFields' => ['shopbite_fulfillment' => 'drone']])->isPickup);
        self::assertFalse(ShippingMethod::fromArray(['name' => 'Abholung', 'customFields' => ['shopbite_fulfillment' => ['pickup']]])->isPickup);
    }

    /** Shopware merges custom fields over the language fallback into `translated`; that wins over the raw field. */
    public function testShippingMethodFulfillmentFromTranslatedCustomFields(): void
    {
        self::assertTrue(ShippingMethod::fromArray(['name' => 'Abholung', 'customFields' => null, 'translated' => ['customFields' => ['shopbite_fulfillment' => 'pickup']]])->isPickup);
        self::assertFalse(ShippingMethod::fromArray(['name' => 'Abholung', 'customFields' => ['shopbite_fulfillment' => 'pickup'], 'translated' => ['customFields' => ['shopbite_fulfillment' => 'delivery']]])->isPickup);
        self::assertTrue(ShippingMethod::fromArray(['name' => 'Abholung', 'customFields' => ['shopbite_fulfillment' => 'pickup'], 'translated' => ['name' => 'Abholung']])->isPickup);
    }

    public function testShippingMethodName(): void
    {
        self::assertSame('Lieferung', ShippingMethod::fromArray(['name' => "Lieferung\x1Bd\x05"])->name);
    }
}
