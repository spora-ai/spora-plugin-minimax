<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax\Support;

use InvalidArgumentException;

/**
 * Centralized accessor for the per-tool `plugin.minimax.{provider}.*` settings.
 *
 * Each tool declares its settings via `#[ToolSetting(key: 'plugin.minimax.{provider}.{field}')]`
 * attributes. Spora's `ToolConfigService::getEffectiveSettings()` returns the merged
 * settings array; this helper pulls the named fields out with a consistent default.
 *
 * Using a static helper rather than passing the settings array around as
 * `array<string, mixed>` keeps the per-tool execute() methods small and the
 * settings key prefix in one place.
 */
final class MiniMaxSettings
{
    public const PROVIDERS = ['image', 'speech', 'music', 'lyrics', 'video'];

    /**
     * Global / international MiniMax API endpoint. Operators in China can
     * override `plugin.minimax.{provider}.base_url` to the China-region
     * endpoint at https://api.minimaxi.com — see the README for details.
     */
    public const DEFAULT_BASE_URL = 'https://api.minimax.io';

    /**
     * @param array<string, mixed> $settings
     */
    public static function apiKey(string $provider, array $settings): string
    {
        self::assertProvider($provider);
        $key = "plugin.minimax.{$provider}.api_key";
        $value = $settings[$key] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param array<string, mixed> $settings
     */
    public static function baseUrl(string $provider, array $settings): string
    {
        self::assertProvider($provider);
        $key = "plugin.minimax.{$provider}.base_url";
        $value = $settings[$key] ?? self::DEFAULT_BASE_URL;
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
        $key = "plugin.minimax.{$provider}.model";
        $value = $settings[$key] ?? $default;
        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public static function intSetting(string $provider, string $field, array $settings, int $default): int
    {
        self::assertProvider($provider);
        $key = "plugin.minimax.{$provider}.{$field}";
        $value = $settings[$key] ?? null;
        if (is_numeric($value)) {
            $int = (int) $value;
            return $int > 0 ? $int : $default;
        }
        return $default;
    }

    private static function assertProvider(string $provider): void
    {
        if (!in_array($provider, self::PROVIDERS, true)) {
            throw new InvalidArgumentException("Unknown MiniMax provider '{$provider}'");
        }
    }
}
