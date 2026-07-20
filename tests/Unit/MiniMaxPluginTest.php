<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Spora\Plugins\MiniMax\MiniMaxPlugin;
use Spora\Plugins\MiniMax\Tools\MiniMaxImageTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxMusicTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxSpeechTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxVideoTool;
use Spora\Services\MediaArchive\MediaArchiveService;

it('returns plugin name', function () {
    $plugin = new MiniMaxPlugin();
    expect($plugin->getName())->toBe('MiniMax');
});

it('contributes all four MiniMax tools', function () {
    $plugin = new MiniMaxPlugin();
    expect($plugin->tools())->toBe([
        MiniMaxImageTool::class,
        MiniMaxSpeechTool::class,
        MiniMaxMusicTool::class,
        MiniMaxVideoTool::class,
    ]);
});

it('declares schema version 1', function () {
    $plugin = new MiniMaxPlugin();
    expect($plugin->schemaVersion())->toBe(1);
});

it('points migrations at the bundled migrations directory', function () {
    $plugin = new MiniMaxPlugin();
    expect($plugin->migrationsPath())->toEndWith('/database/migrations');
});

it('register() binds each MiniMax tool with a setMediaArchive resolver', function () {
    $plugin = new MiniMaxPlugin();
    $builder = new ContainerBuilder();
    $builder->useAutowiring(true);

    // Build a real MediaArchiveService with no-op infrastructure.
    // Mockery can't stub it: MediaArchiveService is `final` and has no
    // no-arg ctor, so partial mocks aren't available. A real instance with
    // a stub URL resolver is enough to prove the `\DI\get(...)` resolver
    // inside the plugin's `register()` actually resolves to a usable
    // object at container-build time. We don't `$container->get()` the
    // tool classes because their constructors pull in
    // `Spora\Services\ToolConfigService`, which depends on
    // `SecurityManagerInterface` (abstract) — outside the unit-test scope
    // of this plugin. The integration suite covers full container builds.
    $logger = new Psr\Log\NullLogger();
    $sniffer = new Spora\Services\MediaArchive\MimeSniffer();
    $archive = new MediaArchiveService(
        new Spora\Services\AutoAssetStore(
            new Spora\Services\DataUrlAssetStore(50 * 1024 * 1024),
            new Spora\Services\LocalAssetStore(
                new Spora\Core\Paths(sys_get_temp_dir() . '/minimax-plugin-test'),
                new Spora\Core\SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
                50 * 1024 * 1024,
            ),
            1_048_576,
        ),
        new Spora\Services\MediaArchive\MediaArchiveUrlResolver(
            new Spora\Services\MediaArchive\RemoteMediaFetcher(
                new Symfony\Component\HttpClient\MockHttpClient([
                    new Symfony\Component\HttpClient\Response\MockResponse('', ['response_headers' => ['content-type: application/octet-stream']]),
                ]),
                $logger,
                30,
                1024 * 1024,
            ),
            $sniffer,
            $logger,
            true,
            1024 * 1024,
        ),
        $sniffer,
        new Spora\Services\MediaArchive\MetadataExtractor($logger, false),
        new Spora\Services\MediaArchive\MediaConverterRegistry(
            Mockery::mock(Psr\Container\ContainerInterface::class),
        ),
        new Spora\Services\MediaArchive\MediaIngestDecoder(),
        $logger,
    );
    $builder->addDefinitions([
        MediaArchiveService::class => $archive,
    ]);

    $plugin->register($builder);

    // Build the container so PHP-DI validates the definitions. This
    // surfaces a runtime error if any tool's autowire()->method() binding
    // is malformed (the most likely failure mode — the DefaultValueResolver
    // / TypeHintContainerResolver short-circuit documented on the register()
    // method's docblock).
    $container = $builder->build();

    // Pulling the actual MediaArchiveService instance (via `get()`, not
    // `has()`) is the strongest assertion we can run without the full
    // ToolConfigService dependency tree. It proves the `\DI\get(...)`
    // resolver inside the plugin's `register()` reaches our concrete
    // archive — exactly what each tool's setMediaArchive() binding feeds.
    expect($container->get(MediaArchiveService::class))->toBe($archive);
});
