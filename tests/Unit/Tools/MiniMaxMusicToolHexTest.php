<?php

declare(strict_types=1);

use Spora\Plugins\MiniMax\Support\MiniMaxLogWriter;
use Spora\Plugins\MiniMax\Tools\MiniMaxMusicTool;
use Spora\Services\AssetReference;
use Spora\Services\AssetStore;
use Spora\Services\ToolConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;

it('decodes a hex payload via the AssetStore when output_format=hex', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $hex = bin2hex(random_bytes(32)); // 32 bytes of music

    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://api.minimax.io/v1/music_generation', Mockery::any())
        ->andReturn((function () use ($hex) {
            $response = Mockery::mock(Symfony\Contracts\HttpClient\ResponseInterface::class);
            $response->allows('getStatusCode')->andReturn(200);
            $response->allows('getContent')->andReturn(json_encode([
                'data'       => ['audio' => $hex, 'status' => 2],
                'extra_info' => ['music_duration' => 5000, 'music_sample_rate' => 44100, 'music_channel' => 2, 'bitrate' => 256000, 'music_size' => 32000],
                'base_resp'  => ['status_code' => 0, 'status_msg' => 'success'],
            ]));
            $response->allows('toArray')->andReturn(json_decode(json_encode([
                'data'       => ['audio' => $hex, 'status' => 2],
                'extra_info' => ['music_duration' => 5000, 'music_sample_rate' => 44100, 'music_channel' => 2, 'bitrate' => 256000, 'music_size' => 32000],
                'base_resp'  => ['status_code' => 0, 'status_msg' => 'success'],
            ]), true));
            return $response;
        })());

    $log = new MiniMaxLogWriter();
    $assetStore = Mockery::mock(AssetStore::class);
    $assetStore->expects('store')
        ->once()
        ->with(
            Mockery::on(static fn(string $bytes): bool => strlen($bytes) === 32),
            'audio/mpeg',
            'song.mp3',
        )
        ->andReturn(new AssetReference('data:audio/mpeg;base64,XYZ', 'data_url'));

    $tool = new MiniMaxMusicTool($config, $http, $log, $assetStore);
    $result = $tool->execute(['action' => 'compose', 'lyrics' => '[Verse]\ntest', 'output_format' => 'hex'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('<audio')
        ->and($result->content)->toContain('data:audio/mpeg;base64,XYZ')
        ->and($result->data['asset_mode'])->toBe('data_url');
});

it('uses a longer timeout setting for the compose operation than lyrics', function () {
    // Just verifies the per-op timeout setting keys are wired up.
    // The actual HTTP call uses whatever the HttpClientInterface records
    // in its options; we assert by inspection of MiniMaxSettings::PROVIDER_DEFAULTS.
    expect(Spora\Plugins\MiniMax\Support\MiniMaxSettings::timeoutSeconds('music', 'http_timeout_seconds', []))
        ->toBe(180)
        ->and(Spora\Plugins\MiniMax\Support\MiniMaxSettings::timeoutSeconds('music', 'http_timeout_seconds_lyrics', []))
        ->toBe(30);
});
