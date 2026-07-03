<?php

declare(strict_types=1);

use Spora\Plugins\MiniMax\Support\MiniMaxSettings;

it('returns the per-tool default timeout when no setting is configured', function () {
    expect(MiniMaxSettings::timeoutSeconds('music', 'http_timeout_seconds', []))->toBe(180)
        ->and(MiniMaxSettings::timeoutSeconds('video', 'submit_timeout_seconds', []))->toBe(120)
        ->and(MiniMaxSettings::timeoutSeconds('speech', 'http_timeout_seconds', []))->toBe(60);
});

it('prefers the operator-configured setting over the default', function () {
    $settings = ['plugin.minimax.music.http_timeout_seconds' => 300];
    expect(MiniMaxSettings::timeoutSeconds('music', 'http_timeout_seconds', $settings))->toBe(300);
});

it('falls back to the env var when the setting is missing or invalid', function () {
    putenv('SPORA_TOOL_HTTP_TIMEOUT=45');
    $_ENV['SPORA_TOOL_HTTP_TIMEOUT'] = '45';
    try {
        expect(MiniMaxSettings::timeoutSeconds('video', 'submit_timeout_seconds', []))->toBe(45);
    } finally {
        putenv('SPORA_TOOL_HTTP_TIMEOUT');
        unset($_ENV['SPORA_TOOL_HTTP_TIMEOUT']);
    }
});

it('setting wins over env var when both are present', function () {
    putenv('SPORA_TOOL_HTTP_TIMEOUT=45');
    $_ENV['SPORA_TOOL_HTTP_TIMEOUT'] = '45';
    try {
        $settings = ['plugin.minimax.music.http_timeout_seconds' => 250];
        expect(MiniMaxSettings::timeoutSeconds('music', 'http_timeout_seconds', $settings))->toBe(250);
    } finally {
        putenv('SPORA_TOOL_HTTP_TIMEOUT');
        unset($_ENV['SPORA_TOOL_HTTP_TIMEOUT']);
    }
});

it('throws on unknown provider', function () {
    expect(static fn() => MiniMaxSettings::timeoutSeconds('bogus', 'x', []))
        ->toThrow(InvalidArgumentException::class);
});

it('throws when timeout field is not declared in PROVIDER_DEFAULTS', function () {
    // A typo'd field name would otherwise silently fall back to 30s and
    // reintroduce the short timeouts this method is meant to fix.
    expect(static fn() => MiniMaxSettings::timeoutSeconds('music', 'http_timeout_secondz', []))
        ->toThrow(InvalidArgumentException::class, 'Unknown timeout field');
});
