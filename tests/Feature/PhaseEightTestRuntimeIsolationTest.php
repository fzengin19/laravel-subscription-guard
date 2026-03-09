<?php

declare(strict_types=1);

it('pins deterministic suite discovery and runtime defaults in phpunit xml', function (): void {
    $xpath = phaseEightXmlXPath(phaseEightRepoRoot().'/phpunit.xml.dist');

    $directories = phaseEightXmlNodeValues($xpath, '//testsuite/directory');
    $servers = phaseEightXmlServerMap($xpath);

    expect($directories)
        ->toContain('tests/Feature')
        ->toContain('tests/Unit')
        ->not->toContain('tests')
        ->not->toContain('tests/Live');

    expect($servers)->toMatchArray([
        'APP_ENV' => 'test',
        'APP_MAINTENANCE_DRIVER' => 'file',
        'BCRYPT_ROUNDS' => '4',
        'CACHE_STORE' => 'array',
        'MAIL_MAILER' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'SUBGUARD_QUEUE_CONNECTION' => 'sync',
        'DB_URL' => '',
        'DB_CONNECTION' => 'testing',
        'DB_DATABASE' => ':memory:',
        'TELESCOPE_ENABLED' => 'false',
        'IYZICO_MOCK' => 'true',
    ]);

    expect(file_get_contents(phaseEightRepoRoot().'/.gitignore') ?: '')->not->toContain("\ntestbench.yaml");
});

it('pins live suite discovery to live tree with explicit env file fallback wiring', function (): void {
    $xpath = phaseEightXmlXPath(phaseEightRepoRoot().'/phpunit.live.xml.dist');

    $directories = phaseEightXmlNodeValues($xpath, '//testsuite/directory');
    $servers = phaseEightXmlServerMap($xpath);

    expect($directories)
        ->toBe(['tests/Live'])
        ->not->toContain('tests/Live/Iyzico');

    expect($servers)->toMatchArray([
        'APP_ENV' => 'test',
        'CACHE_STORE' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'DB_CONNECTION' => 'testing',
        'DB_DATABASE' => ':memory:',
        'TELESCOPE_ENABLED' => 'false',
        'SUBGUARD_LIVE_ENV_FILE' => '.env.test',
    ]);
});

it('keeps deterministic runtime defaults active in the normal suite', function (): void {
    expect(config('database.default'))->toBe('testing')
        ->and(config('database.connections.testing.database'))->toBe(':memory:')
        ->and(config('cache.default'))->toBe('array')
        ->and(config('queue.default'))->toBe('sync')
        ->and(config('mail.default'))->toBe('array')
        ->and(config('session.driver'))->toBe('array')
        ->and(config('subscription-guard.queue.connection'))->toBe('sync');
});

it('keeps testbench yaml env entries flat for testbench bootstrap consumers', function (): void {
    $yaml = \Symfony\Component\Yaml\Yaml::parseFile(phaseEightRepoRoot().'/testbench.yaml');
    $envEntries = $yaml['env'] ?? [];

    expect($envEntries)->not->toBeEmpty();

    foreach ($envEntries as $entry) {
        expect($entry)->toBeString();
    }

    expect($envEntries)->toContain('DB_DATABASE=:memory:');
});

function phaseEightRepoRoot(): string
{
    return dirname(__DIR__, 2);
}

function phaseEightXmlXPath(string $path): \DOMXPath
{
    $document = new \DOMDocument;
    $document->load($path);

    return new \DOMXPath($document);
}

function phaseEightXmlNodeValues(\DOMXPath $xpath, string $expression): array
{
    $values = [];

    foreach ($xpath->query($expression) ?: [] as $node) {
        $values[] = trim($node->textContent);
    }

    return $values;
}

function phaseEightXmlServerMap(\DOMXPath $xpath): array
{
    $servers = [];

    foreach ($xpath->query('//php/server') ?: [] as $server) {
        $name = $server->attributes?->getNamedItem('name')?->nodeValue;

        if (! is_string($name) || $name === '') {
            continue;
        }

        $servers[$name] = (string) ($server->attributes?->getNamedItem('value')?->nodeValue ?? '');
    }

    return $servers;
}
