<?php

declare(strict_types=1);

use Spora\Plugins\MiniMax\Support\MiniMaxLogWriter;
use Spora\Plugins\MiniMax\Tools\MiniMaxMusicTool;
use Spora\Services\ToolConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class MiniMaxMusicToolTestLiterals
{
    public const PROMPT_SUNNY_DAY = 'a sunny day';
    public const CDN_URL_SONG = 'https://cdn.example/song.mp3';
    public const EDIT_PROMPT_SADDER = 'make it sadder';
}

function minimaxResponse(int $status, string $body): ResponseInterface
{
    $response = Mockery::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn($status);
    $response->allows('getContent')->andReturn($body);
    return $response;
}

// --- compose (default operation when no `action` is passed) ---

it('returns an error when the API key is missing', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([]);

    $http = Mockery::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();

    $tool = new MiniMaxMusicTool($config, $http, $log);

    $result = $tool->execute(['prompt' => MiniMaxMusicToolTestLiterals::PROMPT_SUNNY_DAY], 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('API key is not configured');
});

it('returns an error when neither prompt nor lyrics is supplied for compose', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['plugin.minimax.music.api_key' => 'k']);

    $http = Mockery::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();

    $tool = new MiniMaxMusicTool($config, $http, $log);

    $result = $tool->execute(['action' => 'compose'], 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('at least a `prompt` or `lyrics`');
});

it('parses the music response and returns the audio URL for compose', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['plugin.minimax.music.api_key' => 'k']);

    $http = Mockery::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();

    $http->expects('request')
        ->with('POST', 'https://api.minimax.io/v1/music_generation', Mockery::on(function ($opts) {
            return ($opts['json']['model'] ?? null) === 'music-2.6'
                && ($opts['json']['output_format'] ?? null) === 'url'
                && ($opts['json']['prompt'] ?? null) === MiniMaxMusicToolTestLiterals::PROMPT_SUNNY_DAY
                && ($opts['json']['lyrics'] ?? null) === '';
        }))
        ->andReturn(minimaxResponse(200, json_encode([
            'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
            'data'      => ['audio_url' => MiniMaxMusicToolTestLiterals::CDN_URL_SONG],
        ])));

    $tool = new MiniMaxMusicTool($config, $http, $log);
    $result = $tool->execute(['action' => 'compose', 'prompt' => MiniMaxMusicToolTestLiterals::PROMPT_SUNNY_DAY], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain(MiniMaxMusicToolTestLiterals::CDN_URL_SONG)
        ->and($result->data['audio_url'])->toBe(MiniMaxMusicToolTestLiterals::CDN_URL_SONG);
});

// --- write_lyrics ---

it('returns an error when write_lyrics is missing prompt and lyrics', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['plugin.minimax.music.api_key' => 'k']);

    $http = Mockery::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();

    $tool = new MiniMaxMusicTool($config, $http, $log);

    $result = $tool->execute(['action' => 'write_lyrics'], 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('`prompt` describing the song');
});

it('parses the lyrics response and returns the song title for write_lyrics', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['plugin.minimax.music.api_key' => 'k']);

    $http = Mockery::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();

    $http->expects('request')
        ->with('POST', 'https://api.minimax.io/v1/lyrics_generation', Mockery::on(function ($opts) {
            return ($opts['json']['mode'] ?? null) === 'write_full_song'
                && ($opts['json']['prompt'] ?? null) === 'a song about the sea'
                && !array_key_exists('lyrics', $opts['json'] ?? []);
        }))
        ->andReturn(minimaxResponse(200, json_encode([
            'base_resp'  => ['status_code' => 0, 'status_msg' => 'success'],
            'song_title' => 'Tides',
            'lyrics'     => "[Verse]\nWaves on the shore\n[Chorus]\nTides, oh tides",
            'style_tags' => 'dream pop, ethereal',
        ])));

    $tool = new MiniMaxMusicTool($config, $http, $log);
    $result = $tool->execute(['action' => 'write_lyrics', 'prompt' => 'a song about the sea'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('[Verse]')
        ->and($result->content)->toContain('Tides')
        ->and($result->content)->toContain('dream pop, ethereal')
        ->and($result->data['song_title'])->toBe('Tides');
});

// --- edit_lyrics ---

it('returns an error when edit_lyrics is missing lyrics', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['plugin.minimax.music.api_key' => 'k']);

    $http = Mockery::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();

    $tool = new MiniMaxMusicTool($config, $http, $log);

    $result = $tool->execute(['action' => 'edit_lyrics', 'prompt' => MiniMaxMusicToolTestLiterals::EDIT_PROMPT_SADDER], 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('`lyrics` is required for the edit_lyrics operation');
});

it('parses the lyrics response for edit_lyrics with mode=edit', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['plugin.minimax.music.api_key' => 'k']);

    $http = Mockery::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();

    $existingLyrics = "[Verse]\nBright morning\n[Chorus]\nSun on the waves";

    $http->expects('request')
        ->with('POST', 'https://api.minimax.io/v1/lyrics_generation', Mockery::on(function ($opts) use ($existingLyrics) {
            return ($opts['json']['mode'] ?? null) === 'edit'
                && ($opts['json']['lyrics'] ?? null) === $existingLyrics
                && ($opts['json']['prompt'] ?? null) === MiniMaxMusicToolTestLiterals::EDIT_PROMPT_SADDER;
        }))
        ->andReturn(minimaxResponse(200, json_encode([
            'base_resp'  => ['status_code' => 0, 'status_msg' => 'success'],
            'song_title' => 'Tides (sad)',
            'lyrics'     => "[Verse]\nGrey morning\n[Chorus]\nRain on the waves",
        ])));

    $tool = new MiniMaxMusicTool($config, $http, $log);
    $result = $tool->execute([
        'action'  => 'edit_lyrics',
        'prompt'  => MiniMaxMusicToolTestLiterals::EDIT_PROMPT_SADDER,
        'lyrics'  => $existingLyrics,
    ], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Edited lyrics')
        ->and($result->content)->toContain('Rain on the waves')
        ->and($result->data['song_title'])->toBe('Tides (sad)');
});

// --- discriminator ---

it('falls back to the first declared operation when action is absent', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['plugin.minimax.music.api_key' => 'k']);

    $http = Mockery::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();

    // No `action` argument — should dispatch to `compose` and hit /v1/music_generation.
    $http->expects('request')
        ->with('POST', 'https://api.minimax.io/v1/music_generation', Mockery::any())
        ->andReturn(minimaxResponse(200, json_encode([
            'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
            'data'      => ['audio_url' => MiniMaxMusicToolTestLiterals::CDN_URL_SONG],
        ])));

    $tool = new MiniMaxMusicTool($config, $http, $log);
    $result = $tool->execute(['prompt' => 'lo-fi beat'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->data['audio_url'])->toBe(MiniMaxMusicToolTestLiterals::CDN_URL_SONG);
});

it('returns an error for an unknown action', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['plugin.minimax.music.api_key' => 'k']);

    $http = Mockery::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();

    $tool = new MiniMaxMusicTool($config, $http, $log);
    $result = $tool->execute(['action' => 'karaoke', 'prompt' => 'something'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Unknown music operation');
});
