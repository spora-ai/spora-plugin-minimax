<?php

declare(strict_types=1);

use Spora\Plugins\MiniMax\Support\MiniMaxLogWriter;
use Spora\Plugins\MiniMax\Tools\MiniMaxLyricsTool;
use Spora\Services\ToolConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

it('returns an error when the API key is missing', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([]);

    $http = Mockery::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();

    $tool = new MiniMaxLyricsTool($config, $http, $log);

    $result = $tool->execute(['mode' => 'write_full_song', 'prompt' => 'a song about the sea'], 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('API key is not configured');
});

it('returns an error when mode is edit but lyrics is missing', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['plugin.minimax.lyrics.api_key' => 'k']);

    $http = Mockery::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();

    $tool = new MiniMaxLyricsTool($config, $http, $log);

    $result = $tool->execute(['mode' => 'edit', 'prompt' => 'make it sadder'], 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('`lyrics` is required');
});

it('parses the lyrics response and returns the song title', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['plugin.minimax.lyrics.api_key' => 'k']);

    $http = Mockery::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();

    $http->expects('request')
        ->with('POST', 'https://api.minimaxi.io/v1/lyrics_generation', Mockery::on(function ($opts) {
            return ($opts['json']['mode'] ?? null) === 'write_full_song'
                && ($opts['json']['prompt'] ?? null) === 'a song about the sea';
        }))
        ->andReturn(Mockery::mock(ResponseInterface::class)->allows([
            'getStatusCode' => 200,
            'getContent'    => json_encode([
                'base_resp'  => ['status_code' => 0, 'status_msg' => 'success'],
                'song_title' => 'Tides',
                'lyrics'     => "[Verse]\nWaves on the shore\n[Chorus]\nTides, oh tides",
                'style_tags' => 'dream pop, ethereal',
            ]),
        ]));

    $tool = new MiniMaxLyricsTool($config, $http, $log);
    $result = $tool->execute(['mode' => 'write_full_song', 'prompt' => 'a song about the sea'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('[Verse]')
        ->and($result->content)->toContain('Tides')
        ->and($result->data['song_title'])->toBe('Tides');
});
