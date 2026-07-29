<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax;

use DI\ContainerBuilder;
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

    public function migrationsPath(): string
    {
        return __DIR__ . '/../database/migrations';
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
     * short-circuited to `null` by `DefaultValueResolver` before the
     * type-hint resolver runs, so the tools' `?MediaArchiveService
     * $mediaArchive = null` ctor params never get autowired. The same
     * trick bites `autowire()->method('setMediaArchive')` if the
     * setter's parameter is left implicit (`InvalidDefinition`).
     * Binding the resolver explicitly via `\DI\get(...)` short-circuits
     * both paths.
     *
     * `setLocalAssetStore` is wired for the speech + music tools so
     * their audio payloads always land at
     * `/api/v1/assets/<token>.mp3` — never inlined as a
     * `data:audio/mpeg;base64,…` URI (the chat UI sanitizer truncates
     * long base64 to `[data-omitted]` and the base64 itself burns
     * tokens). Image + video are URL-only upstream payloads, no
     * LocalAssetStore needed.
     */
    public function register(ContainerBuilder $builder): void
    {
        $archiveService  = \DI\get(MediaArchiveService::class);
        $localAssetStore = \DI\get(LocalAssetStore::class);

        $builder->addDefinitions([
            MiniMaxImageTool::class  => \DI\autowire()->method('setMediaArchive', $archiveService),
            MiniMaxSpeechTool::class => \DI\autowire()
                ->method('setMediaArchive', $archiveService)
                ->method('setLocalAssetStore', $localAssetStore),
            MiniMaxMusicTool::class  => \DI\autowire()
                ->method('setMediaArchive', $archiveService)
                ->method('setLocalAssetStore', $localAssetStore),
            MiniMaxVideoTool::class  => \DI\autowire()->method('setMediaArchive', $archiveService),
        ]);
    }
}
