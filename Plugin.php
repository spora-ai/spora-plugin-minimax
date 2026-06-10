<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax;

use DI\ContainerBuilder;
use Spora\Plugins\PluginInterface;
use Spora\Plugins\MiniMax\Tools\MiniMaxImageTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxLyricsTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxMusicTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxSpeechTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxVideoTool;

final class MiniMaxPlugin implements PluginInterface
{
    public function getName(): string
    {
        return 'MiniMax';
    }

    /** @return array<string, string> */
    public function autoload(): array
    {
        return ['Spora\\Plugins\\MiniMax\\' => __DIR__ . '/src'];
    }

    /** @return array<class-string<\Spora\Tools\ToolInterface>> */
    public function tools(): array
    {
        return [
            MiniMaxImageTool::class,
            MiniMaxSpeechTool::class,
            MiniMaxMusicTool::class,
            MiniMaxLyricsTool::class,
            MiniMaxVideoTool::class,
        ];
    }

    /** @return array<string, class-string<\Spora\Drivers\LLMDriverInterface>> */
    public function drivers(): array
    {
        return [];
    }

    /** @return string[] */
    public function recipePaths(): array
    {
        return [];
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function migrationsPath(): ?string
    {
        return __DIR__ . '/database/migrations';
    }

    public function register(ContainerBuilder $builder): void
    {
        // No DI bindings for v1 — the support classes are stateless and the tools
        // receive their dependencies (HttpClientInterface, LoggerInterface,
        // ToolConfigService) via constructor injection from Spora's container.
    }
}
