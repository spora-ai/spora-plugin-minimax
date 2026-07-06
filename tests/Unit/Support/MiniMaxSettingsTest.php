<?php

declare(strict_types=1);

use Spora\Plugins\MiniMax\Support\MiniMaxSettings;

it('returns the per-tool default timeout when no setting is configured', function () {
    expect(MiniMaxSettings::timeoutSeconds('music', 'http_timeout_seconds', []))->toBe(180)
        ->and(MiniMaxSettings::timeoutSeconds('video', 'submit_timeout_seconds', []))->toBe(120)
        ->and(MiniMaxSettings::timeoutSeconds('speech', 'http_timeout_seconds', []))->toBe(60);
});

it('prefers the operator-configured setting over the default', function () {
    $settings = ['http_timeout_seconds' => 300];
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
        $settings = ['http_timeout_seconds' => 250];
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

it('reads bare-key settings for every provider', function () {
    $settings = [
        'api_key'                  => 'k',
        'base_url'                 => 'https://api.minimaxi.com',
        'model'                    => 'MiniMax-Hailuo-2.3',
        'poll_interval_seconds'    => '7',
        'poll_timeout_seconds'     => '777',
        'submit_timeout_seconds'   => '111',
        'retrieve_timeout_seconds' => '22',
    ];

    expect(MiniMaxSettings::apiKey('video', $settings))->toBe('k')
        ->and(MiniMaxSettings::baseUrl('video', $settings))->toBe('https://api.minimaxi.com')
        ->and(MiniMaxSettings::model('video', $settings, 'fallback'))->toBe('MiniMax-Hailuo-2.3')
        ->and(MiniMaxSettings::intSetting('video', 'poll_interval_seconds', $settings, 10))->toBe(7)
        ->and(MiniMaxSettings::intSetting('video', 'poll_timeout_seconds', $settings, 600))->toBe(777)
        ->and(MiniMaxSettings::timeoutSeconds('video', 'submit_timeout_seconds', $settings))->toBe(111)
        ->and(MiniMaxSettings::timeoutSeconds('video', 'retrieve_timeout_seconds', $settings))->toBe(22);
});

it('falls back to defaults for bare-key settings when none are configured', function () {
    expect(MiniMaxSettings::apiKey('video', []))->toBe('')
        ->and(MiniMaxSettings::baseUrl('video', []))->toBe('https://api.minimax.io')
        ->and(MiniMaxSettings::model('video', [], 'MiniMax-Hailuo-2.3'))->toBe('MiniMax-Hailuo-2.3')
        ->and(MiniMaxSettings::intSetting('video', 'poll_interval_seconds', [], 10))->toBe(10)
        ->and(MiniMaxSettings::timeoutSeconds('video', 'submit_timeout_seconds', []))->toBe(120)
        ->and(MiniMaxSettings::timeoutSeconds('video', 'retrieve_timeout_seconds', []))->toBe(30);
});
