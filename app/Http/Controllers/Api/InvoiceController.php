<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Booking invoices (one per settled payment).
 *
 *   GET /api/client/bookings/{id}/invoice      JSON for the on-screen invoice (booking's customer)
 *   GET /api/client/bookings/{id}/invoice.pdf  PDF download (booking's customer)
 *   GET /api/store/bookings/{id}/invoice.pdf   PDF download (owner of the booking's store)
 *   GET /api/admin/bookings/{id}/invoice.pdf   PDF download (admin)
 *
 * 404 when the booking is not visible to the caller or has no completed/refunded payment.
 */
class InvoiceController extends Controller
{
    public function clientShow(Request $request, int $id): JsonResponse
    {
        $resolved = $this->resolve(Booking::where('client_id', $request->user()->id), $id);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$booking, $payment] = $resolved;

        return response()->json(['success' => true, 'data' => InvoiceService::build($booking, $payment)]);
    }

    public function clientPdf(Request $request, int $id)
    {
        return $this->download(Booking::where('client_id', $request->user()->id), $id);
    }

    public function storePdf(Request $request, int $id)
    {
        $store = $request->user()->store;
        if (!$store) {
            return response()->json(['success' => false, 'message' => 'Store not found.'], 404);
        }

        return $this->download(Booking::where('store_id', $store->id), $id);
    }

    public function adminPdf(int $id)
    {
        return $this->download(Booking::query(), $id);
    }

    private function download($query, int $id)
    {
        $resolved = $this->resolve($query, $id);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$booking, $payment] = $resolved;

        $invoice = InvoiceService::build($booking, $payment);
        $pdf = InvoiceService::pdf($booking, $payment, $invoice);
        $name = $invoice['file_name'];

        return new Response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Length'      => (string) strlen($pdf),
            'Content-Disposition' => 'attachment; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name),
            'Cache-Control'       => 'private, no-store',
            'X-Invoice-Number'    => $invoice['invoice_number'],
        ]);
    }

    /** @return array{0: Booking, 1: \App\Models\Payment}|JsonResponse */
    private function resolve($query, int $id)
    {
        $booking = $query->with(['venue', 'store', 'client'])->find($id);
        if (!$booking) {
            return response()->json(['success' => false, 'message' => 'Booking not found.'], 404);
        }

        $payment = InvoiceService::paymentFor($booking);
        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => 'An invoice is available once this booking has been paid.',
            ], 404);
        }

        return [$booking, $payment];
    }
}
