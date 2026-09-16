<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax;

use Psr\Log\LoggerInterface;
use Spora\Events\ContainerBuildingEvent;
use Spora\Plugins\AbstractPlugin;
use Spora\Plugins\MiniMax\Tools\MiniMaxImageTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxMediaArchiveResolver;
use Spora\Plugins\MiniMax\Tools\MiniMaxMusicTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxSpeechTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxVideoTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxVideoV1Tool;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaAssetReader;
use Spora\Speech\SpeechToTextProviderInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class MiniMaxPlugin extends AbstractPlugin implements EventSubscriberInterface
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
            MiniMaxVideoV1Tool::class,
        ];
    }

    /**
     * Contributes MiniMax's `asr-1.0` speech-to-text model as a
     * {@see SpeechToTextProviderInterface}. Discovered by
     * {@see \Spora\Speech\SpeechToTextRegistry}; first configured
     * provider wins per transcribe request.
     *
     * @return list<class-string<SpeechToTextProviderInterface>>
     */
    public function speechToTextProviders(): array
    {
        return [MiniMaxTranscribeProvider::class];
    }

    /**
     * Subscribe to the framework's boot-time events.
     *
     * - {@see ContainerBuildingEvent} fires once per process, before the
     *   DI container is built. {@see self::onContainerBuilding()} adds
     *   bindings for the five tools, the STT provider, and the media-archive
     *   resolver so PHP-DI can autowire them at request time.
     *
     * @return array<class-string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ContainerBuildingEvent::class => 'onContainerBuilding',
        ];
    }

    /**
     * Plugin-shipped Skills live as siblings under `<plugin>/skills/<slug>/SKILL.md`.
     * Each of the four tools gets one Skill (`minimax-image`, `minimax-speech`,
     * `minimax-music`, `minimax-video`) plus a legacy companion for music
     * (`minimax-music-legacy`) that signposts the Aug 20 2026 deprecation.
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
    public function onContainerBuilding(ContainerBuildingEvent $event): void
    {
        $builder        = $event->builder();
        $archiveService  = \DI\get(MediaArchiveService::class);
        $localAssetStore = \DI\get(LocalAssetStore::class);
        $logger          = \DI\get(LoggerInterface::class);

        $builder->addDefinitions([
            // Resolver is registered as a DI factory so PHP-DI resolves
            // the in-container MediaAssetReader + logger before the
            // constructor runs. Inlining the construction here would
            // pass unresolved `\DI\get(...)` Reference objects into the
            // `final` class constructor.
            MiniMaxMediaArchiveResolver::class => static function (
                MediaAssetReader $reader,
                ?LoggerInterface $logger,
            ): MiniMaxMediaArchiveResolver {
                return new MiniMaxMediaArchiveResolver(
                    static fn(string $id, ?int $userId): ?array
                        => $reader->readAsset($id, $userId),
                    $logger,
                );
            },

            MiniMaxImageTool::class  => \DI\autowire()
                ->method('setMediaArchive', $archiveService)
                ->method('setLogger', $logger),

            // The provider has no nullable ctor params (no Optional
            // MediaArchive / LocalAssetStore wiring needed), so plain
            // \DI\autowire() resolves ToolConfigService + HttpClientInterface
            // from the container.
            MiniMaxTranscribeProvider::class => \DI\autowire(),

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
                ->method('setLogger', $logger)
                ->method('setMediaArchiveResolver', \DI\get(MiniMaxMediaArchiveResolver::class)),
            MiniMaxVideoV1Tool::class => \DI\autowire()
                ->method('setMediaArchive', $archiveService)
                ->method('setLogger', $logger)
                ->method('setMediaArchiveResolver', \DI\get(MiniMaxMediaArchiveResolver::class)),
        ]);
    }
}
