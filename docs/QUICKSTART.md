# Quickstart

Use this guide to reach a safe first-success path without jumping straight into live provider traffic.

## Goal

By the end of this quickstart, you should have:

- the package installed
- migrations published and run
- a basic understanding of where provider and license configuration lives
- and a local validation path through simulated webhook intake

## 1. Install And Publish

If you have not already done so:

```bash
composer require fzengin19/laravel-subscription-guard
php artisan vendor:publish --tag="laravel-subscription-guard-config"
php artisan vendor:publish --tag="laravel-subscription-guard-migrations"
php artisan migrate
```

If you need the full bootstrap flow, read [Installation](INSTALLATION.md) first.

## 2. Review The Config Surface

Open:

- `config/subscription-guard.php`

At minimum, understand these groups:

- `providers`
- `webhooks`
- `queue`
- `billing`
- `license`

The public reference for those settings is [Configuration](CONFIGURATION.md).

## 3. Use A Local-Only Validation Path First

Do not start with real iyzico or PayTR credentials unless you intentionally want live provider validation.

For a local first-success path, run:

```bash
php artisan subguard:simulate-webhook paytr payment.success
php artisan subguard:simulate-webhook iyzico payment.success
```

These commands validate the local webhook-intake path without requiring real provider callbacks.

## 4. Confirm The Runtime Shape

Know the default queues the package expects:

- `subguard-billing`
- `subguard-webhooks`
- `subguard-notifications`

If your host app will process jobs asynchronously, make sure your queue worker setup includes them.

## 5. Know What Comes Next

After the quickstart, your next step depends on what you are doing:

- provider setup and current provider model: [Providers](PROVIDERS.md)
- route and callback surface: [API](API.md)
- licensing overview: [Licensing](LICENSING.md)
- practical examples: [Recipes](RECIPES.md)

## 6. When To Use Live Sandbox

Move to live sandbox validation only when:

- your local install path is already working
- you intentionally want real iyzico sandbox traffic
- and you can provide environment-owned credentials and a reachable callback URL

That path is separate from the basic quickstart and should not be your default first step.

## Related Documents

- [Installation](INSTALLATION.md)
- [Configuration](CONFIGURATION.md)
- [Commands](COMMANDS.md)
- [Live Sandbox](LIVE-SANDBOX.md)
- [FAQ](FAQ.md)
