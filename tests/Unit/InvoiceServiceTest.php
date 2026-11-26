<?php

namespace Tests\Unit;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Store;
use App\Models\User;
use App\Models\Venue;
use App\Services\InvoiceService;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Invoice data built from in-memory models (no database).
 */
class InvoiceServiceTest extends TestCase
{
    private function booking(array $overrides = []): Booking
    {
        $booking = new Booking();
        $booking->forceFill(array_merge([
            'id'              => 3821,
            'booking_number'  => 'BK-8A6CB53B944A1',
            'event_name'      => 'Surprise Party',
            'event_type'      => 'birthday_parties',
            'expected_guests' => 50,
            'event_date'      => '2026-11-11',
            'start_time'      => '17:00:00',
            'end_time'        => '21:00:00',
            'duration_hours'  => 4,
            'base_price'      => 160000,
            'amenities_price' => 60000,
            'discount_amount' => 0,
            'tax_amount'      => 11000,
            'total_amount'    => 231000,
            'payment_status'  => 'paid',
        ], $overrides));
        $booking->setRelation('venue', (new Venue())->forceFill(['name' => 'Clifton Rooftop Lounge', 'address' => 'Clifton Block 5', 'city' => 'Karachi']));
        $booking->setRelation('store', (new Store())->forceFill(['name' => 'Karachi Event Centre', 'address' => 'Plot 7, Clifton', 'city' => 'Karachi', 'phone' => '0321-0000000', 'email' => 'info@example.pk']));
        $booking->setRelation('client', (new User())->forceFill(['name' => 'Adeel Abbasi', 'email' => 'adeel@example.pk', 'phone' => '0345-0000000']));

        return $booking;
    }

    private function payment(array $overrides = []): Payment
    {
        return (new Payment())->forceFill(array_merge([
            'id'                => 123,
            'status'            => 'completed',
            'payment_method'    => 'payd_fast',
            'amount'            => 231000,
            'currency'          => 'PKR',
            'gateway_amount'    => 15015,
            'gateway_currency'  => 'ZAR',
            'exchange_rate'     => 0.065,
            'transaction_id'    => 'PF140088833821',
            'payment_reference' => 'EG-3821-123-ABCDEFGH',
            'paid_at'           => Carbon::parse('2026-10-08 22:02:00', 'UTC'),
        ], $overrides));
    }

    public function test_invoice_number_uses_local_year_and_padded_payment_id(): void
    {
        $this->assertSame('INV-2026-000123', InvoiceService::invoiceNumber($this->payment()));
        // 31 Dec 21:00 UTC is already 1 Jan in Pakistan
        $this->assertSame('INV-2027-000123', InvoiceService::invoiceNumber($this->payment(['paid_at' => Carbon::parse('2026-12-31 21:00:00', 'UTC')])));
    }

    public function test_file_name_is_filesystem_safe(): void
    {
        $this->assertSame('EventGenius-Invoice-INV-2026-000123-BK-ABC123.pdf', InvoiceService::fileName('INV-2026-000123', 'BK-ABC123'));
        $this->assertSame('EventGenius-Invoice-INV-2026-000123-BK-A-B-C.pdf', InvoiceService::fileName('INV-2026-000123', 'BK/A "B"\\C'));
        $this->assertSame('EventGenius-Invoice-INV-2026-000123.pdf', InvoiceService::fileName('INV-2026-000123', null));
    }

    public function test_paid_invoice_uses_stored_amounts(): void
    {
        $inv = InvoiceService::build($this->booking(), $this->payment());

        $this->assertSame('paid', $inv['status']);
        $this->assertSame('EventGenius-Invoice-INV-2026-000123-BK-8A6CB53B944A1.pdf', $inv['file_name']);
        $this->assertSame([160000.0, 60000.0], array_column($inv['line_items'], 'amount'));
        $this->assertSame(3200.0, $inv['line_items'][0]['unit_price']);
        $this->assertEquals(220000, $inv['totals']['subtotal']);
        $this->assertEquals(11000, $inv['totals']['tax']);
        $this->assertEquals(231000, $inv['totals']['total']);
        $this->assertEquals(231000, $inv['totals']['amount_paid']);
        $this->assertEquals(0, $inv['totals']['balance_due']);
        $this->assertSame('Tax (5%)', $inv['totals']['tax_label']);
        $this->assertSame('PayFast (card)', $inv['payment']['method_label']);
        $this->assertSame(15015.0, $inv['payment']['gateway_amount']);
        $this->assertSame('Clifton Block 5, Karachi', $inv['booking']['venue']['address']);
        $this->assertSame('5:00 PM – 9:00 PM', $inv['booking']['time_label']);
    }

    public function test_refunded_and_offline_payments(): void
    {
        $inv = InvoiceService::build(
            $this->booking(['amenities_price' => 0, 'tax_amount' => 8000, 'total_amount' => 168000, 'payment_status' => 'refunded']),
            $this->payment(['status' => 'refunded', 'amount' => 168000, 'payment_method' => 'cash', 'gateway_amount' => null, 'refunded_at' => Carbon::parse('2026-10-10 10:00:00', 'UTC')])
        );

        $this->assertSame('refunded', $inv['status']);
        $this->assertCount(1, $inv['line_items']);
        $this->assertEquals(168000, $inv['totals']['refunded']);
        $this->assertEquals(0, $inv['totals']['balance_due']);
        $this->assertSame('Cash', $inv['payment']['method_label']);
        $this->assertNull($inv['payment']['gateway_amount']);
    }

    public function test_template_renders_key_figures(): void
    {
        $inv = InvoiceService::build($this->booking(), $this->payment());
        $html = view('invoices.booking', ['invoice' => $inv, 'logo' => ''])->render();

        $this->assertStringContainsString('INV-2026-000123', $html);
        $this->assertStringContainsString('PKR 231,000.00', $html);
        $this->assertStringContainsString('ZAR 15,015.00', $html);
        $this->assertStringContainsString('BK-8A6CB53B944A1', $html);
    }
}
