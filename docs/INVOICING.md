# Invoicing

Use this document to understand how the package generates invoices, renders PDFs, and dispatches payment notifications.

## Concept

Invoices are post-payment artifacts. The package automatically generates an `Invoice` record when a payment completes successfully, renders a PDF (when a PDF engine is available), and notifies the subscribable model.

## Invoice Generation Trigger

Invoice creation is handled by `DispatchBillingNotificationsJob` when it receives a `payment.completed` event.

The flow:

1. `PaymentChargeJob` completes a successful charge
2. It dispatches `DispatchBillingNotificationsJob` with event `payment.completed`
3. The job locates the transaction
4. Creates an `Invoice` record via `firstOrCreate` (idempotent on `transaction_id`)
5. Renders a PDF
6. Sends an `InvoicePaidNotification` to the subscribable model

## Invoice Model

The `Invoice` model uses soft deletes and stores:

| Field | Type | Purpose |
|---|---|---|
| `invoice_number` | string | Unique identifier (auto-generated) |
| `transaction_id` | integer | Link to the source transaction |
| `subscribable_type` | string | Polymorphic owner type |
| `subscribable_id` | integer | Polymorphic owner ID |
| `status` | string | Always `paid` at creation |
| `issue_date` | date | Set to creation time |
| `due_date` | date | Set to creation time |
| `paid_at` | datetime | Set to creation time |
| `subtotal` | float | Transaction amount |
| `tax_amount` | float | Transaction tax amount |
| `total_amount` | float | subtotal + tax_amount |
| `currency` | string | From transaction |
| `pdf_path` | string | Relative path to generated PDF |
| `metadata` | array | Additional context |

Note: `status` is in the model's `$guarded` array — it cannot be mass-assigned.

## Invoice Number Format

Invoice numbers are generated as:

```
INV-{YYYYMMDD}-{RANDOM_8_CHARS}-{TRANSACTION_ID}
```

Example: `INV-20260407-A3BF9K2X-142`

The random component uses `Str::random(8)` uppercased.

## PDF Rendering

`InvoicePdfRenderer` generates invoice PDFs with a two-tier approach:

### With Spatie Laravel PDF

If `Spatie\LaravelPdf\Facades\Pdf` is available, the renderer generates an HTML-based PDF:

- Content includes invoice number, total amount, and currency
- Output is saved to `subguard/invoices/{safe_invoice_number}.pdf` on the `local` disk

### Without PDF Engine

If the Spatie PDF package is not installed, the renderer creates a plain text fallback file at the same path.

The PDF content is currently minimal — it includes the invoice number, total amount, and currency. It does not include line items, company details, or tax breakdowns.

### Storage Location

PDFs are stored at:

```
storage/app/subguard/invoices/{invoice_number}.pdf
```

Special characters in the invoice number are replaced with underscores for filesystem safety.

## Notification

After invoice creation, the package dispatches `InvoicePaidNotification` to the subscribable model (typically the User).

### Channels

| Channel | Condition |
|---|---|
| `mail` | Always |
| `database` | Only when a `notifications` table exists and the notifiable has a `getKey()` method |

### Mail Content

The mail notification includes:

- Subject: `Invoice paid: {invoice_number}`
- Body: invoice number, formatted amount with currency
- PDF path (as text, not as attachment)

### Database Content

```json
{
  "type": "invoice.paid",
  "recipient_type": "user",
  "invoice_number": "INV-20260407-A3BF9K2X-142",
  "amount": 99.99,
  "currency": "TRY",
  "pdf_path": "subguard/invoices/INV_20260407_A3BF9K2X_142.pdf"
}
```

### Queue

Notifications run on the `subguard-notifications` queue (configurable via `SUBGUARD_NOTIFICATIONS_QUEUE`).

## Idempotency

Invoice creation is idempotent on `transaction_id`. If `DispatchBillingNotificationsJob` runs twice for the same transaction, the second run finds the existing invoice via `firstOrCreate` and skips creation. PDF rendering is also skipped if `pdf_path` is already set.

## Other Notification Events

`DispatchBillingNotificationsJob` also handles:

| Event | Action |
|---|---|
| `subscription.cancelled` | Dispatches `SubscriptionCancelledNotification` |
| `payment.failed` | Logged (no dedicated notification class) |
| `dunning.exhausted` | Logged (no dedicated notification class) |
| `subscription.suspended` | Logged (no dedicated notification class) |

Events without dedicated notification classes are logged to the appropriate channel but do not trigger user-facing notifications.

## Limitations

- **Minimal PDF content**: No line items, company info, address, or tax details
- **No customizable templates**: PDF layout is hardcoded in `InvoicePdfRenderer`
- **No tax calculation engine**: Tax amount comes directly from the transaction
- **No credit notes or refund invoices**: Only `paid` invoices are generated
- **PDF as text in email**: The PDF path is included as text in the mail, not as an email attachment
- **Spatie PDF required for real PDFs**: Without the optional dependency, only a text fallback is generated

## Related Documents

- [Domain Billing](DOMAIN-BILLING.md)
- [Events and Jobs](EVENTS-AND-JOBS.md)
- [Configuration](CONFIGURATION.md)
