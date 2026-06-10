<?php

declare(strict_types=1);

use Spora\Plugins\MiniMax\Support\MiniMaxLogWriter;
use Spora\Plugins\MiniMax\Tools\MiniMaxVideoTool;
use Spora\Services\ToolConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

function minimaxVideoResponse(int $status, string $body): ResponseInterface
{
    $response = Mockery::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn($status);
    $response->allows('getContent')->andReturn($body);
    return $response;
}

it('returns an error when the API key is missing', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([]);

    $http = Mockery::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();

    $tool = new MiniMaxVideoTool($config, $http, $log);

    $result = $tool->execute(['prompt' => 'a forest'], 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('API key is not configured');
});

it('returns an error when duration_seconds is invalid', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['plugin.minimax.video.api_key' => 'k']);

    $http = Mockery::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();

    $tool = new MiniMaxVideoTool($config, $http, $log);

    $result = $tool->execute(['prompt' => 'a forest', 'duration_seconds' => 30], 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('duration_seconds');
});

it('polls the task status and returns the download URL when the video finishes', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'plugin.minimax.video.api_key'                  => 'k',
        // Poll quickly: interval=1s, timeout=5s — the happy path doesn't wait
        // long, but the loop still has a real deadline.
        'plugin.minimax.video.poll_interval_seconds'    => '1',
        'plugin.minimax.video.poll_timeout_seconds'     => '5',
    ]);

    $http = Mockery::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();

    // The poll interval is 1 second; we want this test to terminate fast.
    // The tool's poll loop calls getJson('/v1/query/video_generation', ...).
    // We return a single 'success' response to break out of the loop on the
    // first poll, and the inline download URL is used (no second call).
    $http->expects('request')
        ->with('POST', 'https://api.minimaxi.io/v1/video_generation', Mockery::any())
        ->andReturn(minimaxVideoResponse(200, json_encode([
            'base_resp' => ['status_code' => 0, 'status_msg' => 'ok'],
            'task_id'   => 'task-xyz',
        ])));

    $http->expects('request')
        ->with('GET', 'https://api.minimaxi.io/v1/query/video_generation', Mockery::on(function ($opts) {
            return ($opts['query']['task_id'] ?? null) === 'task-xyz';
        }))
        ->andReturn(minimaxVideoResponse(200, json_encode([
            'base_resp'   => ['status_code' => 0, 'status_msg' => 'ok'],
            'status'      => 'success',
            'file'        => ['download_url' => 'https://cdn.example.com/v.mp4'],
        ])));

    $tool = new MiniMaxVideoTool($config, $http, $log);
    $result = $tool->execute(['prompt' => '[Push in] a forest'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('https://cdn.example.com/v.mp4')
        ->and($result->data['video_url'])->toBe('https://cdn.example.com/v.mp4')
        ->and($result->data['task_id'])->toBe('task-xyz');
});
