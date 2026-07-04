<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax;

use Spora\Plugins\AbstractPlugin;
use Spora\Plugins\MiniMax\Tools\MiniMaxImageTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxMusicTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxSpeechTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxVideoTool;

/**
 * Plugin entry point — extending {@see AbstractPlugin} (rather than directly
 * implementing {@see \Spora\Plugins\PluginInterface}) means we only have to
 * override the two hooks we actually use: getName() and tools().
 *
 * The base class provides no-op defaults for autoload(), drivers(),
 * recipePaths(), schemaVersion(), migrationsPath(), and register().
 */
final class MiniMaxPlugin extends AbstractPlugin
{
    public function getName(): string
    {
        return 'MiniMax';
    }

    /** @return array<class-string<\Spora\Tools\ToolInterface>> */
    public function tools(): array
    {
        return [
            MiniMaxImageTool::class,
            MiniMaxSpeechTool::class,
            MiniMaxMusicTool::class,
            MiniMaxVideoTool::class,
        ];
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function migrationsPath(): string
    {
        return __DIR__ . '/../database/migrations';
    }
}
