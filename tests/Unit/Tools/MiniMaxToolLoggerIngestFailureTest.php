<?php

declare(strict_types=1);

use Mockery as M;
use Psr\Log\AbstractLogger;
use Spora\Plugins\MiniMax\Support\MiniMaxLogWriter;
use Spora\Plugins\MiniMax\Tools\MiniMaxImageTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxMusicTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxSpeechTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxVideoTool;
use Spora\Services\AssetReference;
use Spora\Services\AssetStore;
use Spora\Services\AssetTooLargeException;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaArchiveUrlResolver;
use Spora\Services\MediaArchive\MediaConverterRegistry;
use Spora\Services\MediaArchive\MediaIngestDecoder;
use Spora\Services\MediaArchive\MetadataExtractor;
use Spora\Services\MediaArchive\MimeSniffer;
use Spora\Services\MediaArchive\RemoteMediaFetcher;
use Spora\Services\ToolConfigService;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * In-memory PSR-3 logger for ingest-failure assertions. Captures every
 * record so tests can assert both the call happened *and* the message
 * matches the production wording (so the operator can grep for it).
 */
final class CapturingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, Stringable|string $message, array $context = []): void
    {
        $this->records[] = [
            'level'   => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}

/**
 * Build a real `MediaArchiveService` that fetches bytes successfully
 * (so the URL branch returns the body and not an `external` row) but
 * then fails at the AssetStore write step — the only seam that throws
 * an exception which the tools' catch blocks observe.
 *
 * Without this arrangement a failing remote client produces an
 * `external` row (the resolver swallows the fetch error and persists
 * the original URL), which means the tool's catch never fires. The
 * `AssetStore` throws on `store()` because `MediaArchiveService::storeAsset`
 * catches `AssetTooLargeException` and rethrows it as a
 * `MediaArchiveException` — that exception bubbles out of `ingest()`
 * and lands in `MiniMax*Tool::ingest*()`'s `catch (Throwable)`.
 */
function minimaxLoggerArchiveService(): MediaArchiveService
{
    $logger  = new Psr\Log\NullLogger();
    $sniffer = new MimeSniffer();

    // Tiny 8-byte payload for both the HEAD probe and the GET — the
    // HEAD advertises a small content-length so the resolver doesn't
    // skip the body fetch on a "too large" guess, and the GET returns
    // the body itself.
    $http = new MockHttpClient([
        new MockResponse('', [
            'response_headers' => [
                'content-type: image/png',
                'content-length: 8',
            ],
        ]),
        new MockResponse(str_repeat("\x89PNG\r\n\x1a\n", 1)),
    ]);
    $resolver = new MediaArchiveUrlResolver(
        new RemoteMediaFetcher($http, $logger, 30, 1024 * 1024),
        $sniffer,
        $logger,
        true,
        1024 * 1024,
    );

    $throwingStore = new class implements AssetStore {
        public function store(string $bytes, ?string $mime = null, ?string $filename = null): AssetReference
        {
            throw new AssetTooLargeException('forced for test');
        }
    };

    $container = M::mock(Psr\Container\ContainerInterface::class);

    return new MediaArchiveService(
        $throwingStore,
        $resolver,
        $sniffer,
        new MetadataExtractor($logger, false),
        new MediaConverterRegistry($container),
        new MediaIngestDecoder(),
        $logger,
    );
}

function minimaxLoggerArchiveResponse(int $status, string $body): Symfony\Contracts\HttpClient\ResponseInterface
{
    $response = M::mock(Symfony\Contracts\HttpClient\ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn($status);
    $response->allows('getContent')->andReturn($body);
    return $response;
}

it('image tool logs a warning when MediaArchive::ingest throws', function () {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $upstream = M::mock(HttpClientInterface::class);
    $upstream->allows('request')->andReturn(minimaxLoggerArchiveResponse(200, json_encode([
        'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
        'data'      => ['image_urls' => ['https://cdn.example.com/a.png']],
    ])));

    $archive = minimaxLoggerArchiveService();
    $log     = new MiniMaxLogWriter();
    $logger  = new CapturingLogger();

    $tool = new MiniMaxImageTool($config, $upstream, $log, null, null, $archive);
    $tool->setLogger($logger);

    $result = $tool->execute(['prompt' => 'a red fox'], 1);

    // Tool still succeeds — ingest failure is non-fatal. The image
    // URL falls back to the upstream CDN URL.
    expect($result->success)->toBeTrue();

    $matching = array_values(array_filter(
        $logger->records,
        static fn(array $r): bool => $r['level'] === 'warning'
            && str_contains($r['message'], 'MediaArchive ingest failed (image)'),
    ));
    expect($matching)->not->toBeEmpty(
        'expected a warning with message "MediaArchive ingest failed (image)" to be logged on ingest failure',
    );
});

it('speech tool logs a warning when MediaArchive::ingest throws', function () {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $upstream = M::mock(HttpClientInterface::class);
    $upstream->allows('request')->andReturn(minimaxLoggerArchiveResponse(200, json_encode([
        'base_resp'  => ['status_code' => 0, 'status_msg' => 'success'],
        'data'       => ['audio_url' => 'https://cdn.example.com/speech.mp3'],
        'extra_info' => ['audio_size' => 12_345],
    ])));

    $archive = minimaxLoggerArchiveService();
    $log     = new MiniMaxLogWriter();
    $logger  = new CapturingLogger();

    $assetStore = M::mock(AssetStore::class);
    $assetStore->shouldNotReceive('store');

    $tool = new MiniMaxSpeechTool($config, $upstream, $log, $assetStore, null, null, $archive);
    $tool->setLogger($logger);

    $result = $tool->execute(['text' => 'hello', 'voice_id' => 'English_PassionateWarrior'], 7);

    expect($result->success)->toBeTrue();

    $matching = array_values(array_filter(
        $logger->records,
        static fn(array $r): bool => $r['level'] === 'warning'
            && str_contains($r['message'], 'MediaArchive ingest failed (speech)'),
    ));
    expect($matching)->not->toBeEmpty();
});

it('music tool logs a warning when MediaArchive::ingest throws', function () {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $upstream = M::mock(HttpClientInterface::class);
    $upstream->allows('request')->andReturn(minimaxLoggerArchiveResponse(200, json_encode([
        'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
        'data'      => ['audio' => str_repeat('00', 24)],
    ])));

    $archive = minimaxLoggerArchiveService();
    $log     = new MiniMaxLogWriter();
    $logger  = new CapturingLogger();

    // The music tool ships hex output through the configured
    // AssetStore; we use a mock that never gets called (the
    // LocalAssetStore is set as the wire so the configured
    // AssetStore is bypassed). The archive service is what fails.
    $assetStore = M::mock(AssetStore::class);
    $assetStore->shouldNotReceive('store');

    $tool = new MiniMaxMusicTool($config, $upstream, $log, $assetStore, null, null, $archive);
    $tool->setLogger($logger);

    $tmp = sys_get_temp_dir() . '/minimax-logger-music-' . bin2hex(random_bytes(4));
    $tool->setLocalAssetStore(new Spora\Services\LocalAssetStore(
        new Spora\Core\Paths($tmp),
        new Spora\Core\SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        50 * 1024 * 1024,
    ));

    $result = $tool->execute([
        'action'        => 'compose',
        'prompt'        => 'lofi piano',
        'output_format' => 'hex',
    ], 99);

    expect($result->success)->toBeTrue();

    $matching = array_values(array_filter(
        $logger->records,
        static fn(array $r): bool => $r['level'] === 'warning'
            && str_contains($r['message'], 'MediaArchive ingest failed (music)'),
    ));
    expect($matching)->not->toBeEmpty();
});

it('video tool logs a warning when MediaArchive::ingest throws', function () {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key'                => 'k',
        'poll_interval_seconds'  => '1',
        'poll_timeout_seconds'   => '5',
        'submit_timeout_seconds' => '30',
    ]);

    $upstream = M::mock(HttpClientInterface::class);
    $upstream->allows('request')
        ->with('POST', 'https://api.minimax.io/v1/video_generation', M::any())
        ->andReturn(minimaxLoggerArchiveResponse(200, json_encode([
            'base_resp' => ['status_code' => 0, 'status_msg' => 'ok'],
            'task_id'   => 'task-xyz',
        ])));
    $upstream->allows('request')
        ->with('GET', 'https://api.minimax.io/v1/query/video_generation', M::any())
        ->andReturn(minimaxLoggerArchiveResponse(200, json_encode([
            'base_resp'    => ['status_code' => 0, 'status_msg' => 'success'],
            'task_id'      => 'task-xyz',
            'status'       => 'Success',
            'file_id'      => 'file-abc-123',
            'video_width'  => 1920,
            'video_height' => 1080,
        ])));
    $upstream->allows('request')
        ->with('GET', 'https://api.minimax.io/v1/files/retrieve', M::any())
        ->andReturn(minimaxLoggerArchiveResponse(200, json_encode([
            'file' => [
                'file_id'      => 'file-abc-123',
                'download_url' => 'https://minimax.example/output.mp4',
            ],
            'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
        ])));

    $archive = minimaxLoggerArchiveService();
    $log     = new MiniMaxLogWriter();
    $logger  = new CapturingLogger();

    $tool = new MiniMaxVideoTool($config, $upstream, $log, null, null, $archive);
    $tool->setLogger($logger);

    $result = $tool->execute(['prompt' => '[Push in] a forest', 'duration_seconds' => '6'], 11);

    expect($result->success)->toBeTrue();

    $matching = array_values(array_filter(
        $logger->records,
        static fn(array $r): bool => $r['level'] === 'warning'
            && str_contains($r['message'], 'MediaArchive ingest failed (video)'),
    ));
    expect($matching)->not->toBeEmpty();
});
