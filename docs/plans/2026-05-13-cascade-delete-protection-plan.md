# Cascade Delete Protection (P0) Implementation Plan

> **For agentic workers:** TDD task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Eliminate the latent data-loss bomb where a `DELETE FROM plans WHERE id = ?` (or a user delete) physically wipes every dependent subscription/license/subscription_item via InnoDB `ON DELETE CASCADE`, ignoring `softDeletes`.

**Architecture:** Add a corrective forward migration (`2026_05_13_090000_fix_cascade_delete_protection.php`) that drops and re-creates four foreign keys (`subscriptions.plan_id`, `licenses.plan_id`, `licenses.user_id`, `subscription_items.plan_id`) as `RESTRICT`, and adds `deleted_at` to `plans` for application-level soft archival. Update the `Plan` model with the `SoftDeletes` trait. Update belongsTo plan relations on `Subscription`, `License`, `SubscriptionItem` to use `withTrashed()` so historical billing keeps resolving its plan after archival. Tests live in `tests/Feature/PhaseFourteenCascadeProtectionTest.php`.

**Tech Stack:** Laravel 11/12/13 migrations, Eloquent SoftDeletes, Pest PHP, SQLite (test DB).

---

## Pre-Flight Verification (completed 2026-05-13)

Verified before TDD begins:

1. **SQLite FK enforcement in tests** — `tests/TestCase.php::getEnvironmentSetUp` sets `'foreign_key_constraints' => true` on the testing connection. Laravel's SQLite driver translates this to `PRAGMA foreign_keys = ON` automatically. Tests will see real FK exceptions; assertions are not theatre.

2. **doctrine/dbal availability** — Not in `require-dev` explicitly; present only as a transitive dep through Laravel. Laravel 11+ no longer needs Doctrine for `dropForeign`/`foreign` on SQLite (uses native table rebuild via SQLite-specific grammar). However, we add it to `require-dev` defensively as part of Task 2 to ensure the migration is robust across all SQLite versions CI hits. If `composer test` passes without adding it on first run, the addition can be reverted before merge.

3. **`SubscriptionItem::plan()` already exists** — confirmed at `src/Models/SubscriptionItem.php:27`. Task 7 only needs to chain `->withTrashed()`, not add the method.

---

## Scope and Non-Scope

**In scope (this branch):**
1. New idempotent fix migration (drop + recreate FKs with `RESTRICT`).
2. `plans.deleted_at` column.
3. `Plan` model `SoftDeletes` trait.
4. `Subscription::plan()`, `License::plan()`, `SubscriptionItem::plan()` use `->withTrashed()`.
5. Regression tests in `PhaseFourteenCascadeProtectionTest.php`.
6. Doc updates: `CHANGELOG.md`, `docs/INSTALLATION.md` (mention fix migration is required), `docs/SECURITY.md` Production Hardening, `docs/01-CURRENT-STATE.md`.
7. Optional: `Plan` model defensive `delete()` override that throws if dependents exist (skipped — DB-level `RESTRICT` already does this and surfaces as `QueryException`. Adding a model-level pre-check is duplicate work and creates a TOCTOU window).

**Out of scope (defer to a different branch):**
- DA-01 `DB::afterCommit` listener audit.
- DA-02 Iyzico cancel idempotency error-code mapping.
- DA-03 `Subscription::transitionTo()` silent fallback.
- Changes to `subscription_items.subscription_id`, `license_usages.license_id`, `license_activations.license_id`, `discounts.coupon_id`, `scheduled_plan_changes.subscription_id`, `transactions.subscription_id`, `transactions.license_id` — these are child-table cascades (parent gone → child becomes orphan) and are deliberately retained as cascade because:
  - `subscription_items` are line items of a subscription. If the subscription is hard-deleted (extremely rare; `softDeletes` is the path), the line items have no semantic life of their own.
  - `license_usages`, `license_activations` are telemetry for a license. Same rationale.
  - `transactions.subscription_id` and `transactions.license_id` are already `nullOnDelete` → preserved for audit.
  - `webhook_calls.transaction_id`, `webhook_calls.subscription_id` are already `nullOnDelete` → preserved.

---

## File Structure

| File | Action | Responsibility |
|------|--------|----------------|
| `database/migrations/2026_05_13_090000_fix_cascade_delete_protection.php` | Create | Drop and recreate 4 FKs as `restrictOnDelete`; add `plans.deleted_at` if missing. Idempotent — survives partial application. Reversible `down()` restores `cascadeOnDelete`. |
| `src/Models/Plan.php` | Modify | Add `use SoftDeletes` trait. |
| `src/Models/Subscription.php` | Modify | Change `plan()` belongsTo to chain `->withTrashed()`. |
| `src/Models/License.php` | Modify | Change `plan()` belongsTo to chain `->withTrashed()`. |
| `src/Models/SubscriptionItem.php` | Modify | Change `plan()` belongsTo to chain `->withTrashed()`. |
| `tests/Feature/PhaseFourteenCascadeProtectionTest.php` | Create | Regression coverage (8 tests). |
| `CHANGELOG.md` | Modify | Add `## [Unreleased]` (or `## [1.3.0]` after release) breaking-impact section. |
| `docs/INSTALLATION.md` | Modify | "Existing installations" upgrade note pointing to the fix migration. |
| `docs/SECURITY.md` | Modify | "Cascade-delete defense" subsection under Production Hardening. |
| `docs/01-CURRENT-STATE.md` | Modify | Reflect completion. |

---

## Migration Idempotency Strategy

The fix migration runs against (a) brand-new installs that already ran `create_subscriptions_table` with the bad cascade, and (b) installs that already migrated v1.2.0. Both paths converge to the same end state.

We do **not** edit the original `create_*_table.php` files — that would change migration class fingerprints and break `migrate:refresh` for users already at v1.2.0. The new migration:

1. Detects current `ON DELETE` action by querying `information_schema.referential_constraints` (MySQL) or `pragma_foreign_key_list` (SQLite). On MySQL, FK names are predictable via Laravel conventions (e.g., `subscriptions_plan_id_foreign`). On SQLite we just `DELETE FROM` and re-create.
2. Uses `Schema::table` with `dropForeign([...])` + `foreign(...)->references(...)->on(...)->restrictOnDelete()`.
3. Wraps each FK swap in a `try { ... } catch (QueryException) { /* already restrict — skip */ }` so partial runs converge.
4. Adds `plans.deleted_at` with `Schema::hasColumn` guard.

`down()` reverses to `cascadeOnDelete` (for `migrate:rollback`) and drops `plans.deleted_at` if no rows are trashed.

---

## Task 1: Failing test — plan cannot be hard-deleted while subscription references it

**Files:**
- Create: `tests/Feature/PhaseFourteenCascadeProtectionTest.php`

- [ ] **Step 1: Write the failing test file scaffold + first test**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\License;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\SubscriptionItem;
use SubscriptionGuard\LaravelSubscriptionGuard\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeUserCascade(): \Illuminate\Database\Eloquent\Model
{
    $user = config('auth.providers.users.model', \SubscriptionGuard\LaravelSubscriptionGuard\Tests\Stubs\User::class);

    return $user::query()->forceCreate([
        'name' => 'Cascade User',
        'email' => 'cascade-'.uniqid().'@example.test',
        'password' => bcrypt('secret'),
    ]);
}

function makePlanCascade(array $overrides = []): Plan
{
    return Plan::query()->forceCreate(array_merge([
        'name' => 'Cascade Plan',
        'slug' => 'cascade-'.uniqid(),
        'price' => 100,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ], $overrides));
}

it('Task 1 — DB refuses to hard-delete a plan that has a subscription', function (): void {
    $user = makeUserCascade();
    $plan = makePlanCascade();

    Subscription::query()->forceCreate([
        'subscribable_type' => $user::class,
        'subscribable_id' => $user->getKey(),
        'plan_id' => $plan->id,
        'provider' => 'iyzico',
        'status' => 'active',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'amount' => 100,
        'currency' => 'TRY',
    ]);

    expect(fn () => DB::table('plans')->where('id', $plan->id)->delete())
        ->toThrow(QueryException::class);

    expect(Subscription::query()->where('plan_id', $plan->id)->exists())->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/PhaseFourteenCascadeProtectionTest.php --filter="Task 1"`
Expected: FAIL — the delete succeeds and cascades (no exception). Subscription row will be missing, second assertion fails. (Specifically the SQLite test DB will report the delete as successful because FK is `CASCADE`.)

- [ ] **Step 3: Commit failing test**

```bash
git add tests/Feature/PhaseFourteenCascadeProtectionTest.php
git commit -m "test(cascade-protection): add failing regression for plan hard-delete cascade"
```

---

## Task 2: Implementation — fix migration

**Files:**
- Create: `database/migrations/2026_05_13_090000_fix_cascade_delete_protection.php`

- [ ] **Step 1: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('plans', 'deleted_at')) {
            Schema::table('plans', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }

        $this->swapCascadeToRestrict('subscriptions', 'plan_id', 'plans');
        $this->swapCascadeToRestrict('licenses', 'plan_id', 'plans');
        $this->swapCascadeToRestrict('licenses', 'user_id', 'users');
        $this->swapCascadeToRestrict('subscription_items', 'plan_id', 'plans');
    }

    public function down(): void
    {
        $this->swapRestrictToCascade('subscriptions', 'plan_id', 'plans');
        $this->swapRestrictToCascade('licenses', 'plan_id', 'plans');
        $this->swapRestrictToCascade('licenses', 'user_id', 'users');
        $this->swapRestrictToCascade('subscription_items', 'plan_id', 'plans');

        if (Schema::hasColumn('plans', 'deleted_at')) {
            Schema::table('plans', function (Blueprint $table): void {
                $table->dropSoftDeletes();
            });
        }
    }

    private function swapCascadeToRestrict(string $childTable, string $column, string $parentTable): void
    {
        try {
            Schema::table($childTable, function (Blueprint $table) use ($column): void {
                $table->dropForeign([$column]);
            });
        } catch (QueryException) {
            // FK already missing — fall through to recreate.
        }

        Schema::table($childTable, function (Blueprint $table) use ($column, $parentTable): void {
            $table->foreign($column)
                ->references('id')
                ->on($parentTable)
                ->restrictOnDelete();
        });
    }

    private function swapRestrictToCascade(string $childTable, string $column, string $parentTable): void
    {
        try {
            Schema::table($childTable, function (Blueprint $table) use ($column): void {
                $table->dropForeign([$column]);
            });
        } catch (QueryException) {
            // FK already missing.
        }

        Schema::table($childTable, function (Blueprint $table) use ($column, $parentTable): void {
            $table->foreign($column)
                ->references('id')
                ->on($parentTable)
                ->cascadeOnDelete();
        });
    }
};
```

- [ ] **Step 2: Run Task 1 test to verify pass**

Run: `vendor/bin/pest tests/Feature/PhaseFourteenCascadeProtectionTest.php --filter="Task 1"`
Expected: PASS — the `QueryException` now fires from the RESTRICT FK.

- [ ] **Step 3: Commit migration**

```bash
git add database/migrations/2026_05_13_090000_fix_cascade_delete_protection.php
git commit -m "fix(cascade-protection): swap plan/user FKs from cascade to restrict + add plans.deleted_at"
```

---

## Task 3: License plan-hard-delete protection

**Files:**
- Modify: `tests/Feature/PhaseFourteenCascadeProtectionTest.php` (append)

- [ ] **Step 1: Append test**

```php
it('Task 3 — DB refuses to hard-delete a plan that has a license', function (): void {
    $user = makeUserCascade();
    $plan = makePlanCascade();

    License::query()->forceCreate([
        'user_id' => $user->getKey(),
        'plan_id' => $plan->id,
        'key' => 'lic-'.uniqid(),
        'status' => 'active',
        'max_activations' => 1,
        'current_activations' => 0,
    ]);

    expect(fn () => DB::table('plans')->where('id', $plan->id)->delete())
        ->toThrow(QueryException::class);

    expect(License::query()->where('plan_id', $plan->id)->exists())->toBeTrue();
});
```

- [ ] **Step 2: Run and verify PASS**

Run: `vendor/bin/pest tests/Feature/PhaseFourteenCascadeProtectionTest.php --filter="Task 3"`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/PhaseFourteenCascadeProtectionTest.php
git commit -m "test(cascade-protection): license plan FK is now restrict"
```

---

## Task 4: User hard-delete protection (license `user_id`)

**Files:**
- Modify: `tests/Feature/PhaseFourteenCascadeProtectionTest.php` (append)

- [ ] **Step 1: Append test**

```php
it('Task 4 — DB refuses to hard-delete a user that owns a license', function (): void {
    $user = makeUserCascade();
    $plan = makePlanCascade();

    License::query()->forceCreate([
        'user_id' => $user->getKey(),
        'plan_id' => $plan->id,
        'key' => 'lic-'.uniqid(),
        'status' => 'active',
        'max_activations' => 1,
        'current_activations' => 0,
    ]);

    expect(fn () => DB::table('users')->where('id', $user->getKey())->delete())
        ->toThrow(QueryException::class);

    expect(License::query()->where('user_id', $user->getKey())->exists())->toBeTrue();
});
```

- [ ] **Step 2: Run and verify PASS** (Task 4 only)

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/PhaseFourteenCascadeProtectionTest.php
git commit -m "test(cascade-protection): license user FK is now restrict"
```

---

## Task 5: `subscription_items.plan_id` is restrict

**Files:**
- Modify: `tests/Feature/PhaseFourteenCascadeProtectionTest.php` (append)

- [ ] **Step 1: Append test**

```php
it('Task 5 — DB refuses to hard-delete a plan that has subscription_items', function (): void {
    $user = makeUserCascade();
    $plan = makePlanCascade();

    $subscription = Subscription::query()->forceCreate([
        'subscribable_type' => $user::class,
        'subscribable_id' => $user->getKey(),
        'plan_id' => $plan->id,
        'provider' => 'iyzico',
        'status' => 'active',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'amount' => 100,
        'currency' => 'TRY',
    ]);

    SubscriptionItem::query()->forceCreate([
        'subscription_id' => $subscription->id,
        'plan_id' => $plan->id,
        'quantity' => 1,
        'unit_price' => 100,
    ]);

    expect(fn () => DB::table('plans')->where('id', $plan->id)->delete())
        ->toThrow(QueryException::class);
});
```

- [ ] **Step 2: Run and verify PASS** (Task 5 only)

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/PhaseFourteenCascadeProtectionTest.php
git commit -m "test(cascade-protection): subscription_items plan FK is now restrict"
```

---

## Task 6: `Plan` model gains `SoftDeletes`

**Files:**
- Modify: `src/Models/Plan.php`

- [ ] **Step 1: Write the failing test first (append to test file)**

```php
it('Task 6 — Plan supports soft delete and is excluded from default queries', function (): void {
    $plan = makePlanCascade();
    $plan->delete();

    expect(Plan::query()->find($plan->id))->toBeNull();
    expect(Plan::withTrashed()->find($plan->id))->not->toBeNull();
    expect(Plan::withTrashed()->find($plan->id)->trashed())->toBeTrue();
});
```

- [ ] **Step 2: Run and verify FAIL** — `Plan` has no `delete()` soft-delete behavior; row is hard-deleted (which now throws if any FK reference exists, but here there are none — so the delete will succeed but `find()` still returns it because `deleted_at` is null. The first assertion will fail.)

Run: `vendor/bin/pest tests/Feature/PhaseFourteenCascadeProtectionTest.php --filter="Task 6"`
Expected: FAIL.

- [ ] **Step 3: Add `SoftDeletes` trait to Plan**

```php
<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Plan extends Model
{
    use SoftDeletes;

    protected $guarded = ['id', 'is_active'];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'limits' => 'array',
            'is_active' => 'bool',
            'price' => 'float',
            'deleted_at' => 'datetime',
        ];
    }

    public function licenses(): HasMany
    {
        return $this->hasMany(License::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
```

- [ ] **Step 4: Run and verify PASS**

- [ ] **Step 5: Commit**

```bash
git add src/Models/Plan.php tests/Feature/PhaseFourteenCascadeProtectionTest.php
git commit -m "feat(plan): add SoftDeletes trait"
```

---

## Task 7: Subscription/License/SubscriptionItem `plan()` relations resolve trashed plans

Required so the billing pipeline keeps working after a plan is archived. Without `withTrashed()`, `$subscription->plan` would return `null` for archived plans, breaking every renewal cycle on historical subscriptions.

**Files:**
- Modify: `src/Models/Subscription.php` (`plan()` method)
- Modify: `src/Models/License.php` (`plan()` method)
- Modify: `src/Models/SubscriptionItem.php` (`plan()` method)

- [ ] **Step 1: Append failing test**

```php
it('Task 7 — Subscription, License, SubscriptionItem resolve their soft-deleted plan via withTrashed', function (): void {
    $user = makeUserCascade();
    $plan = makePlanCascade();

    $subscription = Subscription::query()->forceCreate([
        'subscribable_type' => $user::class,
        'subscribable_id' => $user->getKey(),
        'plan_id' => $plan->id,
        'provider' => 'iyzico',
        'status' => 'active',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'amount' => 100,
        'currency' => 'TRY',
    ]);

    $license = License::query()->forceCreate([
        'user_id' => $user->getKey(),
        'plan_id' => $plan->id,
        'key' => 'lic-'.uniqid(),
        'status' => 'active',
        'max_activations' => 1,
        'current_activations' => 0,
    ]);

    $item = SubscriptionItem::query()->forceCreate([
        'subscription_id' => $subscription->id,
        'plan_id' => $plan->id,
        'quantity' => 1,
        'unit_price' => 100,
    ]);

    // Soft-delete the plan
    $plan->delete();

    // Each model must still resolve the plan via withTrashed
    expect($subscription->fresh()->plan)->not->toBeNull()
        ->and($subscription->fresh()->plan->id)->toBe($plan->id);
    expect($license->fresh()->plan)->not->toBeNull()
        ->and($license->fresh()->plan->id)->toBe($plan->id);
    expect($item->fresh()->plan)->not->toBeNull()
        ->and($item->fresh()->plan->id)->toBe($plan->id);
});
```

- [ ] **Step 2: Run and verify FAIL** — default belongsTo applies the SoftDeletes global scope; the relation returns `null`.

- [ ] **Step 3: Implement — edit the three `plan()` relation methods**

For each of `Subscription`, `License`, `SubscriptionItem`, locate the existing relation:

```php
public function plan(): BelongsTo
{
    return $this->belongsTo(Plan::class);
}
```

And replace with:

```php
public function plan(): BelongsTo
{
    return $this->belongsTo(Plan::class)->withTrashed();
}
```

(Use `Edit` tool, exact string match; verify `BelongsTo` is already imported in each file. If `SubscriptionItem` lacks a `plan()` method, add one.)

- [ ] **Step 4: Run and verify PASS**

- [ ] **Step 5: Commit**

```bash
git add src/Models/Subscription.php src/Models/License.php src/Models/SubscriptionItem.php tests/Feature/PhaseFourteenCascadeProtectionTest.php
git commit -m "fix(relations): plan() resolves trashed plans via withTrashed"
```

---

## Task 8: Subscription/license soft delete still works (paranoia check)

**Files:**
- Modify: `tests/Feature/PhaseFourteenCascadeProtectionTest.php` (append)

- [ ] **Step 1: Append test**

```php
it('Task 8 — Subscription softDelete unaffected by FK changes', function (): void {
    $user = makeUserCascade();
    $plan = makePlanCascade();

    $subscription = Subscription::query()->forceCreate([
        'subscribable_type' => $user::class,
        'subscribable_id' => $user->getKey(),
        'plan_id' => $plan->id,
        'provider' => 'iyzico',
        'status' => 'active',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'amount' => 100,
        'currency' => 'TRY',
    ]);

    $subscription->delete();

    expect(Subscription::query()->find($subscription->id))->toBeNull();
    expect(Subscription::withTrashed()->find($subscription->id))->not->toBeNull();
});
```

- [ ] **Step 2: Run and verify PASS**

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/PhaseFourteenCascadeProtectionTest.php
git commit -m "test(cascade-protection): subscription soft delete still works after FK swap"
```

---

## Task 9: Full suite + PHPStan

- [ ] **Step 1: Run full Pest suite**

Run: `composer test`
Expected: All previous tests pass + 8 new tests. No regressions.

- [ ] **Step 2: Run PHPStan**

Run: `composer analyse`
Expected: Level 5 clean.

- [ ] **Step 3: If green, commit any incidental fixes**

If anything breaks (likely candidate: any test that uses `Plan::find()` after a previous soft-delete in the same DB), fix and commit separately.

---

## Task 10: Documentation

**Files:**
- Modify: `CHANGELOG.md`
- Modify: `docs/INSTALLATION.md`
- Modify: `docs/SECURITY.md`
- Modify: `docs/01-CURRENT-STATE.md`

- [ ] **Step 1: CHANGELOG entry**

Add an `## [Unreleased]` (or `## [1.3.0]` when tagging) section:

```markdown
## [1.3.0] - 2026-05-13

### Fixed (critical)
- **Cascade-delete data loss**: foreign keys `subscriptions.plan_id`,
  `licenses.plan_id`, `licenses.user_id`, and `subscription_items.plan_id`
  were declared `ON DELETE CASCADE`. A `DELETE FROM plans WHERE ...` (or
  user delete) would physically wipe all dependent rows, bypassing
  `softDeletes`. Existing model-level `softDeletes` did NOT protect
  against this because InnoDB enforces FK actions at the database layer.

  New migration `2026_05_13_090000_fix_cascade_delete_protection.php`
  drops and re-creates these FKs as `RESTRICT`, and adds `plans.deleted_at`.
  `Plan` model gains `SoftDeletes`. `Subscription::plan()`,
  `License::plan()`, `SubscriptionItem::plan()` chain `->withTrashed()`.

### BREAKING (behaviour change)
- `User::delete()` no longer cascades to `licenses`. Consuming apps that
  relied on `$user->delete()` to silently clean up licenses must now
  cancel/soft-delete the licenses first, or use `License::forceDelete()`
  on the dependent rows. This is intentional: the previous behaviour
  destroyed audit trails on user-delete and was the second half of the
  P0 fix (defense-in-depth against admin-error and GDPR mis-handling).
  Alternative `nullOnDelete` was considered (preserves audit, allows
  user-delete to proceed) but rejected because the package has no
  out-of-the-box orphan-license cleanup job, and a NULL `user_id`
  silently breaks `License::user()` everywhere. Apps with GDPR
  Right-to-Be-Forgotten obligations should expose an explicit
  `archiveUserLicenses()` flow before calling `User::delete()`.

### Upgrade notes
- After `composer update`, run `php artisan migrate`. The fix migration
  is idempotent and safe to re-run.
- If a plan archive workflow is added in the consuming app, prefer
  `$plan->delete()` (now soft) over `$plan->forceDelete()`.
- Audit user-delete paths for the License impact described in BREAKING.
```

- [ ] **Step 2: INSTALLATION.md upgrade note**

After the existing migration section, append:

```markdown
### Upgrading from v1.2.x to v1.3.0

v1.3.0 adds `2026_05_13_090000_fix_cascade_delete_protection.php` which:
- Adds `deleted_at` to `plans`.
- Swaps four foreign keys from `CASCADE` to `RESTRICT`.

This is required. If you accidentally `DELETE FROM plans` on v1.2.x,
all dependent subscriptions, licenses, and subscription items are
physically wiped — soft-deletes do not protect you. Run:

\`\`\`bash
php artisan migrate
\`\`\`

The migration is idempotent.
```

- [ ] **Step 3: SECURITY.md "Cascade-delete defense" subsection** (under Production Hardening)

```markdown
### Cascade-delete defense

The package defines `RESTRICT` foreign keys on parent references
(`subscriptions.plan_id`, `licenses.plan_id`, `licenses.user_id`,
`subscription_items.plan_id`). This prevents accidental or malicious
`DELETE FROM plans` / `DELETE FROM users` from physically wiping dependent
billing rows. To archive a plan, soft-delete it: `$plan->delete()`.

Do NOT call `$plan->forceDelete()` unless you have explicitly migrated all
dependent subscriptions, licenses, and subscription items to a different
plan first; otherwise the `RESTRICT` constraint will throw `QueryException`.
```

- [ ] **Step 4: CURRENT-STATE.md update**

Replace `## Active Focus` and `## Last Completed Work` v1.2.0 sections with
v1.3.0 cascade-protection narrative.

- [ ] **Step 5: Commit**

```bash
git add CHANGELOG.md docs/INSTALLATION.md docs/SECURITY.md docs/01-CURRENT-STATE.md
git commit -m "docs(cascade-protection): v1.3.0 changelog + upgrade + hardening notes"
```

---

## Task 11: PR + release prep

- [ ] **Step 1: Push branch**

```bash
git push -u origin fix/cascade-delete-protection
```

- [ ] **Step 2: Open PR**

```bash
gh pr create --title "fix(P0): protect against cascade-delete data loss on plans/users" --body "$(cat <<'EOF'
## Summary
- Closes a P0 data-loss vector: `DELETE FROM plans` / `DELETE FROM users` physically wiped all dependent subscriptions, licenses, and subscription items via `ON DELETE CASCADE`. `softDeletes` did not protect because InnoDB enforces FK actions at the DB layer.
- New idempotent fix migration swaps four FKs to `RESTRICT`, adds `plans.deleted_at`, `Plan` gains `SoftDeletes`, and `plan()` relations on `Subscription`/`License`/`SubscriptionItem` chain `->withTrashed()` so archived plans still resolve in billing queries.

## Test plan
- [ ] `composer test` green (8 new tests, full suite unchanged)
- [ ] `composer analyse` PHPStan level 5 clean
- [ ] CI matrix green (PHP 8.3/8.4 × Laravel 11/12/13)

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

- [ ] **Step 3: Wait for CI green, then merge via GitHub UI**

- [ ] **Step 4: After merge, tag v1.3.0**

```bash
git checkout main
git pull
git tag -a v1.3.0 -m "v1.3.0 — Cascade-delete protection"
git push origin v1.3.0
gh release create v1.3.0 --title "v1.3.0 — Cascade-delete protection" --notes-from-tag
```

---

## Self-Review Checklist

- [x] **Spec coverage**: every cascade FK identified in the audit (4 FKs) has a migration step + a test.
- [x] **Placeholder scan**: no TBD/TODO/implement-later strings.
- [x] **Type consistency**: all task code uses `Plan`, `Subscription`, `License`, `SubscriptionItem` model names consistently; `RESTRICT` is spelled `restrictOnDelete()` (Laravel 11+ canonical) everywhere; `withTrashed()` is the SoftDeletes method (not `withDeleted`).
- [x] **Migration idempotency**: try/catch around `dropForeign`, `hasColumn` guard around `softDeletes`, reversible `down()`.
- [x] **Relation breakage**: explicitly covered (Task 7) — without `withTrashed()`, billing would break the moment any plan is archived.
- [x] **Out-of-scope cascade FKs**: documented why we keep them.
