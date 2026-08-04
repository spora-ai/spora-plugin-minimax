<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax;

use DI\ContainerBuilder;
use Psr\Log\LoggerInterface;
use Spora\Plugins\AbstractPlugin;
use Spora\Plugins\MiniMax\Tools\MiniMaxImageTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxMusicTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxSpeechTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxVideoTool;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MediaArchiveService;

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

    /**
     * Plugin-shipped Skills live as siblings under `<plugin>/skills/<slug>/SKILL.md`.
     * Each of the four tools gets one Skill (`minimax-image`, `minimax-speech`,
     * `minimax-music`, `minimax-video`) so the Agent can pull per-tool usage
     * notes on demand instead of relying on the LLM's memory of the full
     * MiniMax surface area.
     *
     * `is_dir` guard keeps the override side-effect-free when the directory
     * is absent (e.g. checkout without the `skills/` subtree).
     *
     * @return string[]
     */
    public function skillPaths(): array
    {
        $path = \dirname(__DIR__) . '/skills';
        return is_dir($path) ? [$path] : [];
    }

    /**
     * Agent-template files for the MiniMax plugin.
     *
     * @return string[]
     */
    public function agentTemplatePaths(): array
    {
        return [
            __DIR__ . '/../agent-templates',
        ];
    }

    /**
     * PHP-DI quirk: nullable ctor params with `= null` defaults are
     * short-circuited to null by DefaultValueResolver before the type-hint
     * resolver runs, so the tools' optional `?MediaArchiveService` /
     * `?LoggerInterface` ctor params never get autowired. Explicit
     * `\DI\get(...)` resolvers + setter calls are the workaround.
     *
     * `setLocalAssetStore` is wired only for the speech + music tools so
     * their audio payloads always land at `/api/v1/assets/<token>.mp3`
     * (the chat UI sanitizer truncates long base64 to `[data-omitted]`).
     */
    public function register(ContainerBuilder $builder): void
    {
        $archiveService  = \DI\get(MediaArchiveService::class);
        $localAssetStore = \DI\get(LocalAssetStore::class);
        $logger          = \DI\get(LoggerInterface::class);

        $builder->addDefinitions([
            MiniMaxImageTool::class  => \DI\autowire()
                ->method('setMediaArchive', $archiveService)
                ->method('setLogger', $logger),
            MiniMaxSpeechTool::class => \DI\autowire()
                ->method('setMediaArchive', $archiveService)
                ->method('setLocalAssetStore', $localAssetStore)
                ->method('setLogger', $logger),
            MiniMaxMusicTool::class  => \DI\autowire()
                ->method('setMediaArchive', $archiveService)
                ->method('setLocalAssetStore', $localAssetStore)
                ->method('setLogger', $logger),
            MiniMaxVideoTool::class  => \DI\autowire()
                ->method('setMediaArchive', $archiveService)
                ->method('setLogger', $logger),
        ]);
    }
}
