<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax;

use DI\ContainerBuilder;
use Spora\Plugins\AbstractPlugin;
use Spora\Plugins\MiniMax\Tools\MiniMaxImageTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxMusicTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxSpeechTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxVideoTool;
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
     * Force PHP-DI to invoke each tool's `setMediaArchive(MediaArchiveService)`
     * setter after autowiring the constructor, with the resolver looked up
     * explicitly.
     *
     * Why this exists
     * ---------------
     * PHP-DI's resolver chain runs {@see \Invoker\ParameterResolver\DefaultValueResolver}
     * BEFORE the container's type-hint resolver, so any nullable ctor
     * parameter with a `= null` default is short-circuited to `null` — the
     * container never even considers injecting it. The plugin's four tool
     * ctors declare `?MediaArchiveService $mediaArchive = null` for legacy /
     * test convenience, which means autowire alone leaves the trait's
     * `$mediaArchive` field unset, `mediaArchive()` throws `LogicException`
     * on every call, and the chat falls back to the upstream CDN URL with
     * nothing ever written to `media_assets`.
     *
     * The same trick bites `autowire()->method('setMediaArchive')` if the
     * setter's parameter is left implicit — `DefaultValueResolver` claims
     * the slot before `TypeHintContainerResolver` runs, the parameter is
     * marked `#UNDEFINED#`, and container compilation fails with
     * `InvalidDefinition`. Binding the resolver explicitly via
     * `\DI\get(MediaArchiveService::class)` short-circuits that path.
     *
     * See https://php-di.org/doc/php-definitions.html (`autowire()->method()`)
     * for the API used here.
     */
    public function register(ContainerBuilder $builder): void
    {
        $archiveService = \DI\get(MediaArchiveService::class);

        $builder->addDefinitions([
            MiniMaxImageTool::class  => \DI\autowire()->method('setMediaArchive', $archiveService),
            MiniMaxSpeechTool::class => \DI\autowire()->method('setMediaArchive', $archiveService),
            MiniMaxMusicTool::class  => \DI\autowire()->method('setMediaArchive', $archiveService),
            MiniMaxVideoTool::class  => \DI\autowire()->method('setMediaArchive', $archiveService),
        ]);
    }
}
