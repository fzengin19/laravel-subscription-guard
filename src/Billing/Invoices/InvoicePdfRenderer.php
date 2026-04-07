<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Billing\Invoices;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Invoice;
use Throwable;

final class InvoicePdfRenderer
{
    public function render(Invoice $invoice): string
    {
        $invoiceNumber = (string) $invoice->getAttribute('invoice_number');

        if ($invoiceNumber === '') {
            throw new RuntimeException('Invoice number cannot be empty.');
        }

        $directory = 'subguard/invoices';
        $safeFileName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $invoiceNumber) ?? $invoiceNumber;
        $relativePath = $directory.'/'.$safeFileName.'.pdf';

        $pdfFacade = '\\Spatie\\LaravelPdf\\Facades\\Pdf';

        if (class_exists($pdfFacade)) {
            try {
                $safeInvoiceNumber = htmlspecialchars($invoiceNumber, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $safeCurrency = htmlspecialchars((string) $invoice->getAttribute('currency'), ENT_QUOTES | ENT_HTML5, 'UTF-8');

                $html = '<h1>Invoice '.$safeInvoiceNumber.'</h1>'
                    .'<p>Total: '.number_format((float) $invoice->getAttribute('total_amount'), 2).' '
                    .$safeCurrency.'</p>';

                $pdfFacade::html($html)->disk('local')->save($relativePath);

                return $relativePath;
            } catch (Throwable $e) {
                Log::warning('PDF generation failed for invoice '.$invoiceNumber.': '.$e->getMessage());
            }
        }

        Storage::disk('local')->put($relativePath, 'Invoice '.$invoiceNumber.' generated without PDF engine.');

        return $relativePath;
    }
}
