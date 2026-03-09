<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Tests\Support\Live;

use Illuminate\Support\Facades\File;

final class IyzicoSandboxRunContext
{
    private function __construct(
        private readonly string $phase,
        private readonly string $runId,
        private readonly string $artifactsDirectory,
    ) {}

    public static function create(string $phase): self
    {
        $normalizedPhase = trim($phase) === '' ? 'live' : trim($phase);
        $runId = sprintf('%s-%s-%s', $normalizedPhase, now()->format('YmdHis'), bin2hex(random_bytes(3)));
        $artifactsDirectory = base_path('storage/app/testing/iyzico-sandbox/'.$runId);

        return new self($normalizedPhase, $runId, $artifactsDirectory);
    }

    public function phase(): string
    {
        return $this->phase;
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function artifactsDirectory(): string
    {
        return $this->artifactsDirectory;
    }

    public function artifactPath(string $fileName): string
    {
        return rtrim($this->artifactsDirectory, '/').'/'.ltrim($fileName, '/');
    }

    public function writeJsonArtifact(string $fileName, array $payload): void
    {
        File::ensureDirectoryExists($this->artifactsDirectory);
        File::put($this->artifactPath($fileName), json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function scopedValue(string $prefix): string
    {
        return sprintf('%s-%s', trim($prefix), $this->runId);
    }
}
