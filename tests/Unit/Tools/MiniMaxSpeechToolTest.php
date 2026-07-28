<?php

declare(strict_types=1);

use Spora\Plugins\MiniMax\Support\MiniMaxLogWriter;
use Spora\Plugins\MiniMax\Tests\Support\MinimaxFixtures;
use Spora\Plugins\MiniMax\Tools\MiniMaxSpeechTool;
use Spora\Services\AssetReference;
use Spora\Services\AssetStore;
use Spora\Services\ToolConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

function minimaxMockResponse(int $status, string $body): ResponseInterface
{
    $response = Mockery::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn($status);
    $response->allows('getContent')->andReturn($body);
    if ($status >= 200 && $status < 300) {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $response->allows('toArray')->andReturn($decoded);
        }
    }
    return $response;
}

it('embeds a CDN URL directly when audio_url is present', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://api.minimax.io/v1/t2a_v2', Mockery::any())
        ->andReturn(minimaxMockResponse(200, json_encode([
            'data'       => ['audio_url' => 'https://cdn.example/speech.mp3', 'status' => 2, 'ced' => ''],
            'extra_info' => ['audio_length' => 1000, 'audio_size' => 12345, 'usage_characters' => 50, 'audio_format' => 'mp3'],
            'base_resp'  => ['status_code' => 0, 'status_msg' => 'success'],
        ])));

    $log = new MiniMaxLogWriter();
    $assetStore = Mockery::mock(AssetStore::class);
    $assetStore->shouldNotReceive('store');

    $tool = new MiniMaxSpeechTool($config, $http, $log, $assetStore);
    $result = $tool->execute(['text' => 'Hello world'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('<audio')
        ->and($result->content)->toContain('https://cdn.example/speech.mp3')
        ->and($result->content)->toContain('Use the same audio embed above')
        ->and($result->data['audio_url'])->toBe('https://cdn.example/speech.mp3')
        ->and($result->data['asset_mode'])->toBeNull();
});

it('decodes a hex payload and routes it through the AssetStore', function () {
    $fixture = MinimaxFixtures::speechHexPayload();

    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://api.minimax.io/v1/t2a_v2', Mockery::any())
        ->andReturn(minimaxMockResponse(200, json_encode($fixture['response'])));

    $log = new MiniMaxLogWriter();
    $assetStore = Mockery::mock(AssetStore::class);
    $assetStore->expects('store')
        ->once()
        ->with(
            Mockery::on(static fn(string $bytes): bool => strlen($bytes) === 115350),
            'audio/mpeg',
            'speech.mp3',
        )
        ->andReturn(new AssetReference('data:audio/mpeg;base64,AAA', 'data_url'));

    $tool = new MiniMaxSpeechTool($config, $http, $log, $assetStore);
    $result = $tool->execute($fixture['request'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('<audio')
        ->and($result->content)->toContain('data:audio/mpeg;base64,AAA')
        ->and($result->content)->toContain('Use the same audio embed above')
        ->and($result->data['asset_mode'])->toBe('data_url')
        ->and($result->data['audio_size'])->toBe(115350);
});

it('routes the hex payload to the local store when over the auto threshold', function () {
    $fixture = MinimaxFixtures::speechHexPayload();

    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')->andReturn(minimaxMockResponse(200, json_encode($fixture['response'])));

    $log = new MiniMaxLogWriter();
    $assetStore = Mockery::mock(AssetStore::class);
    $assetStore->expects('store')
        ->once()
        ->andReturn(new AssetReference('/api/v1/assets/abc123def456.mp3', 'local'));

    $tool = new MiniMaxSpeechTool($config, $http, $log, $assetStore);
    $result = $tool->execute($fixture['request'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('<audio')
        ->and($result->content)->toContain('/api/v1/assets/abc123def456.mp3')
        ->and($result->content)->toContain('Use the same audio embed above')
        ->and($result->data['asset_mode'])->toBe('local');
});

it('routes the hex payload through the injected LocalAssetStore regardless of payload size', function () {
    // Real LocalAssetStore backed by a per-test tmp directory. The plugin
    // wires `setLocalAssetStore` from PHP-DI in production; this exercises
    // the wire to prove the speech tool bypasses the configured
    // `asset_store.mode` (which would otherwise inline the small 115 KB
    // payload as `data:audio/mpeg;base64,…` and break the chat bubble).
    $fixture = MinimaxFixtures::speechHexPayload();

    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://api.minimax.io/v1/t2a_v2', Mockery::any())
        ->andReturn(minimaxMockResponse(200, json_encode($fixture['response'])));

    $log = new MiniMaxLogWriter();

    $tmp = sys_get_temp_dir() . '/minimax-speech-local-asset-' . bin2hex(random_bytes(4));
    $local = new Spora\Services\LocalAssetStore(
        new Spora\Core\Paths($tmp),
        new Spora\Core\SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        50 * 1024 * 1024,
    );

    // AssetStore must NOT be touched — the whole point of the LocalAssetStore
    // bypass is that the configured AssetStore (which on a default install
    // is an AutoAssetStore with a 1 MiB threshold) is irrelevant.
    $assetStore = Mockery::mock(AssetStore::class);
    $assetStore->shouldNotReceive('store');

    $tool = new MiniMaxSpeechTool($config, $http, $log, $assetStore);
    $tool->setLocalAssetStore($local);
    $result = $tool->execute($fixture['request'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('<audio')
        ->and($result->content)->not->toContain('data:audio/mpeg;base64,')
        ->and($result->content)->toContain('Use the same audio embed above')
        ->and($result->data['asset_mode'])->toBe('local')
        ->and($result->data['asset_url'])->toStartWith('/api/v1/assets/');

    // The on-disk file should match what we fed in: same byte length,
    // written under <tmp>/storage/assets/. `Paths::storage('assets')`
    // appends `/storage/assets` to the basePath. LocalAssetStore derives
    // the filename extension from the `mime` hint we passed
    // (`audio/mpeg` → `.mp3`).
    $entries = glob($tmp . '/storage/assets/*') ?: [];
    expect($entries)->toHaveCount(1)
        ->and(pathinfo($entries[0], PATHINFO_EXTENSION))->toBe('mp3')
        ->and(filesize($entries[0]))->toBe(115350);
});

it('returns a clear failure on odd-length hex payload', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')->andReturn(minimaxMockResponse(200, json_encode([
        'data'       => ['audio' => 'abc', 'status' => 2, 'ced' => ''], // 3 chars, odd
        'extra_info' => ['audio_length' => 100, 'audio_size' => 1, 'usage_characters' => 1, 'audio_format' => 'mp3'],
        'base_resp'  => ['status_code' => 0, 'status_msg' => 'success'],
    ])));

    $log = new MiniMaxLogWriter();
    $assetStore = Mockery::mock(AssetStore::class);
    $assetStore->shouldNotReceive('store');

    $tool = new MiniMaxSpeechTool($config, $http, $log, $assetStore);
    $result = $tool->execute(['text' => 'Hello world'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('unsupported');
});
