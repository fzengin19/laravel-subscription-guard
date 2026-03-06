<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Tests\Support\Live;

use Throwable;

final class IyzicoSandboxCleanupRegistry
{
    private array $callbacks = [];

    public function register(string $label, callable $callback): void
    {
        $this->callbacks[] = [
            'label' => $label,
            'callback' => $callback,
        ];
    }

    public function run(): array
    {
        $cleanupDebt = [];

        foreach (array_reverse($this->callbacks) as $entry) {
            try {
                ($entry['callback'])();
            } catch (Throwable $throwable) {
                $cleanupDebt[] = [
                    'label' => $entry['label'],
                    'reason' => $throwable->getMessage(),
                ];
            }
        }

        return [
            'cleanup_debt' => $cleanupDebt,
        ];
    }
}
