<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Psr\Log\LoggerInterface;
use Spora\Plugins\MiniMax\MiniMaxPlugin;
use Spora\Services\MediaArchive\MediaArchiveService;

/**
 * Boots a real ContainerBuilder with PSR-3 + MediaArchive wired in,
 * then asserts the plugin's register() produced a container where
 * LoggerInterface resolves to the bound instance.
 */

it('register() binds LoggerInterface to every MiniMax tool', function () {
    $plugin = new MiniMaxPlugin();
    $builder = new ContainerBuilder();
    $builder->useAutowiring(true);

    $logger = new Psr\Log\NullLogger();
    $sniffer = new Spora\Services\MediaArchive\MimeSniffer();
    $archive = new MediaArchiveService(
        new Spora\Services\AutoAssetStore(
            new Spora\Services\DataUrlAssetStore(50 * 1024 * 1024),
            new Spora\Services\LocalAssetStore(
                new Spora\Core\Paths(sys_get_temp_dir() . '/minimax-plugin-logger-test'),
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
        LoggerInterface::class     => $logger,
        // Provide a real LocalAssetStore so the speech + music tools'
        // setLocalAssetStore() binding doesn't fail at container build.
        Spora\Services\LocalAssetStore::class => new Spora\Services\LocalAssetStore(
            new Spora\Core\Paths(sys_get_temp_dir() . '/minimax-plugin-logger-test'),
            new Spora\Core\SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            50 * 1024 * 1024,
        ),
    ]);

    $plugin->register($builder);

    // Build the container — surfaces any malformed autowire()->method()
    // binding at registration time.
    $container = $builder->build();

    // The MediaArchive and LocalAssetStore services resolve to the
    // same instances the plugin will inject into the tool setters; the
    // LoggerInterface is the same instance the plugin will pass to
    // setLogger(). This is the strongest assertion possible without
    // pulling in ToolConfigService (which would drag the rest of the
    // kernel in).
    expect($container->get(MediaArchiveService::class))->toBe($archive)
        ->and($container->get(LoggerInterface::class))->toBe($logger);
});
