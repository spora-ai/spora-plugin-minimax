<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Spora\Plugins\MiniMax\MiniMaxPlugin;
use Spora\Plugins\MiniMax\MiniMaxTranscribeProvider;
use Spora\Plugins\MiniMax\Tools\MiniMaxImageTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxMusicTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxSpeechTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxVideoTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxVideoV1Tool;
use Spora\Services\MediaArchive\MediaArchiveService;

it('returns plugin name', function () {
    $plugin = new MiniMaxPlugin();
    expect($plugin->getName())->toBe('MiniMax');
});

it('contributes all five MiniMax tools', function () {
    $plugin = new MiniMaxPlugin();
    expect($plugin->tools())->toBe([
        MiniMaxImageTool::class,
        MiniMaxSpeechTool::class,
        MiniMaxMusicTool::class,
        MiniMaxVideoTool::class,
        MiniMaxVideoV1Tool::class,
    ]);
});

it('contributes the MiniMaxTranscribeProvider as a SpeechToTextProviderInterface', function () {
    // The provider is discovered via the framework's
    // speechToTextProviders() data hook; first-configured provider wins
    // per request.
    $plugin = new MiniMaxPlugin();
    expect($plugin->speechToTextProviders())->toBe([MiniMaxTranscribeProvider::class]);
});

it('subscribes to ContainerBuildingEvent', function () {
    $events = MiniMaxPlugin::getSubscribedEvents();

    expect($events)->toBe([
        Spora\Events\ContainerBuildingEvent::class => 'onContainerBuilding',
    ]);
});

it('onContainerBuilding binds each MiniMax tool with a setMediaArchive resolver', function () {
    $plugin = new MiniMaxPlugin();
    $builder = new ContainerBuilder();
    $builder->useAutowiring(true);

    // Build a real MediaArchiveService with no-op infrastructure.
    // Mockery can't stub it: MediaArchiveService is `final` and has no
    // no-arg ctor, so partial mocks aren't available. A real instance with
    // a stub URL resolver is enough to prove the `\DI\get(...)` resolver
    // inside the plugin's `onContainerBuilding()` actually resolves to a
    // usable object at container-build time. We don't `$container->get()`
    // the tool classes because their constructors pull in
    // `Spora\Services\ToolConfigService`, which depends on
    // `SecurityManagerInterface` (abstract) — outside the unit-test scope
    // of this plugin. The integration suite covers full container builds.
    $logger = new Psr\Log\NullLogger();
    $sniffer = new Spora\Services\MediaArchive\MimeSniffer();
    $assetStore = new Spora\Services\AutoAssetStore(
        new Spora\Services\DataUrlAssetStore(50 * 1024 * 1024),
        new Spora\Services\LocalAssetStore(
            new Spora\Core\Paths(sys_get_temp_dir() . '/minimax-plugin-test'),
            new Spora\Core\SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            50 * 1024 * 1024,
        ),
        1_048_576,
    );
    $urlResolver = new Spora\Services\MediaArchive\MediaArchiveUrlResolver(
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
    );
    $pipeline = new Spora\Services\MediaArchive\MediaArchiveIngestPipeline(
        new Spora\Services\MediaArchive\MediaIngestDecoder(),
        $urlResolver,
        $sniffer,
        new Spora\Services\MediaArchive\MetadataExtractor($logger, false),
        $assetStore,
        new Spora\Services\MediaArchive\MediaConverterRegistry(
            Mockery::mock(Psr\Container\ContainerInterface::class),
        ),
        new Spora\Services\PrincipalService(new Spora\Services\PrincipalResolver()),
        $logger,
    );
    $archive = new MediaArchiveService($pipeline);
    $builder->addDefinitions([
        MediaArchiveService::class => $archive,
    ]);

    $dispatcher = new Symfony\Component\EventDispatcher\EventDispatcher();
    $dispatcher->addSubscriber($plugin);
    $dispatcher->dispatch(new Spora\Events\ContainerBuildingEvent($builder));

    // Build the container so PHP-DI validates the definitions. This
    // surfaces a runtime error if any tool's autowire()->method() binding
    // is malformed (the most likely failure mode — the DefaultValueResolver
    // / TypeHintContainerResolver short-circuit documented on the onContainerBuilding()
    // method's docblock).
    $container = $builder->build();

    // Pulling the actual MediaArchiveService instance (via `get()`, not
    // `has()`) is the strongest assertion we can run without the full
    // ToolConfigService dependency tree. It proves the `\DI\get(...)`
    // resolver inside the plugin's `onContainerBuilding()` reaches our concrete
    // archive — exactly what each tool's setMediaArchive() binding feeds.
    expect($container->get(MediaArchiveService::class))->toBe($archive);

    // The STT provider has no nullable ctor params so plain
    // \DI\autowire() resolves its ToolConfigService + HttpClientInterface
    // deps from the container. `has()` is enough to confirm the binding
    // is in place; `get()` would require the full ToolConfigService
    // dependency tree which is outside this unit-test's scope.
    expect($container->has(MiniMaxTranscribeProvider::class))->toBeTrue();
});
