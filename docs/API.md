# API

## Webhook Endpoints

- `POST /subguard/webhooks/{provider}`
- `POST /subguard/webhooks/{provider}/3ds/callback`
- `POST /subguard/webhooks/{provider}/checkout/callback`

`{provider}` supports registered provider keys such as `iyzico` and `paytr`.

## Webhook Simulator Command

```bash
php artisan subguard:simulate-webhook {provider} {event}
```

Options:

- `--event-id=`
- `--subscription-id=`
- `--transaction-id=`
- `--amount=`

## Operational Commands

- `subguard:process-renewals`
- `subguard:process-dunning`
- `subguard:suspend-overdue`
- `subguard:process-metered-billing`
- `subguard:process-plan-changes`
- `subguard:sync-license-revocations`
- `subguard:sync-license-heartbeats`
