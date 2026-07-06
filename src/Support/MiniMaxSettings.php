<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax\Support;

use InvalidArgumentException;

/**
 * Centralized accessor for the per-tool settings of every MiniMax provider.
 *
 * Each tool declares its settings via `#[ToolSetting(key: '{field}')]`
 * attributes with bare field names (no `plugin.minimax.{provider}.` prefix).
 * Spora's `ToolConfigService::getEffectiveSettings()` returns the merged
 * settings array; this helper pulls the named fields out with a consistent
 * default.
 *
 * Using a static helper rather than passing the settings array around as
 * `array<string, mixed>` keeps the per-tool execute() methods small and
 * the default-per-provider lookup in one place.
 */
final class MiniMaxSettings
{
    public const PROVIDERS = ['image', 'speech', 'music', 'video'];

    /**
     * Global / international MiniMax API endpoint. Operators in China can
     * override `base_url` on the relevant tool's settings to the China-region
     * endpoint at https://api.minimaxi.com — see the README for details.
     */
    public const DEFAULT_BASE_URL = 'https://api.minimax.io';

    /**
     * Default HTTP timeouts per provider / stage. These match the
     * `#[ToolSetting]` defaults on each tool so behaviour is identical
     * whether the operator configures the setting or not.
     *
     * Why these values:
     *  - image: 60 s — single-shot diffusion; longer than expected.
     *  - speech: 60 s — single-shot TTS; ~7 s for a short utterance.
     *  - music.compose: 180 s — MiniMax composition is the slowest
     *    single endpoint in this plugin and routinely exceeds 90 s on
     *    the operator's network (see plugin log id=5 captured 2026-07-03).
     *  - music.lyrics: 30 s — pure text endpoint.
     *  - video.submit: 120 s — MiniMax queues the task server-side; the
     *    submit response itself can take >30 s to return the task_id.
     *  - video.poll: 600 s — total polling window (not per-request).
     *  - video.retrieve: 30 s — file-retrieval API call.
     */
    public const PROVIDER_DEFAULTS = [
        'image'  => ['http_timeout_seconds' => 60],
        'speech' => ['http_timeout_seconds' => 60],
        'music'  => [
            'http_timeout_seconds'         => 180,
            'http_timeout_seconds_lyrics'  => 30,
        ],
        'video'  => [
            'submit_timeout_seconds'  => 120,
            'poll_timeout_seconds'    => 600,
            'retrieve_timeout_seconds' => 30,
        ],
    ];

    /**
     * @param array<string, mixed> $settings
     */
    public static function apiKey(string $provider, array $settings): string
    {
        self::assertProvider($provider);
        $value = $settings['api_key'] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param array<string, mixed> $settings
     */
    public static function baseUrl(string $provider, array $settings): string
    {
        self::assertProvider($provider);
        $value = $settings['base_url'] ?? self::DEFAULT_BASE_URL;
        if (!is_string($value) || trim($value) === '') {
            return self::DEFAULT_BASE_URL;
        }
        return rtrim(trim($value), '/');
    }

    /**
     * @param array<string, mixed> $settings
     */
    public static function model(string $provider, array $settings, string $default): string
    {
        self::assertProvider($provider);
        $value = $settings['model'] ?? $default;
        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public static function intSetting(string $provider, string $field, array $settings, int $default): int
    {
        self::assertProvider($provider);
        $value = $settings[$field] ?? null;
        if (is_numeric($value)) {
            $int = (int) $value;
            return $int > 0 ? $int : $default;
        }
        return $default;
    }

    /**
     * Resolve an HTTP timeout in seconds from the layered config:
     *   per-tool setting (operator configured) → Spora-wide env
     *   (`SPORA_TOOL_HTTP_TIMEOUT`) → hard-coded `PROVIDER_DEFAULTS[$provider][$field]`.
     *
     * Multi-operation tools (music, video) call this per stage with a
     * different `$field` (e.g. `http_timeout_seconds_lyrics`).
     *
     * Throws on unknown $field — typos in field names would otherwise
     * silently fall back to the global default and reintroduce the
     * short timeouts this method is meant to fix.
     *
     * @param array<string, mixed> $settings
     */
    public static function timeoutSeconds(string $provider, string $field, array $settings): int
    {
        self::assertProvider($provider);
        if (!array_key_exists($field, self::PROVIDER_DEFAULTS[$provider] ?? [])) {
            throw new InvalidArgumentException(
                "Unknown timeout field '{$field}' for provider '{$provider}'. "
                . "Add it to MiniMaxSettings::PROVIDER_DEFAULTS if intentional.",
            );
        }
        $default = (int) self::PROVIDER_DEFAULTS[$provider][$field];

        // Operator-configured setting wins. Only consulted when the key is
        // explicitly present in $settings — otherwise env / default applies.
        if (array_key_exists($field, $settings) && is_numeric($settings[$field]) && (int) $settings[$field] > 0) {
            return (int) $settings[$field];
        }
        $env = (int) ($_ENV['SPORA_TOOL_HTTP_TIMEOUT'] ?? getenv('SPORA_TOOL_HTTP_TIMEOUT') ?: 0);
        return $env > 0 ? $env : $default;
    }

    private static function assertProvider(string $provider): void
    {
        if (!in_array($provider, self::PROVIDERS, true)) {
            throw new InvalidArgumentException("Unknown MiniMax provider '{$provider}'");
        }
    }
}
