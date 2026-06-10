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

    $result = $tool->execute(['prompt' => 'a forest', 'duration_seconds' => '30'], 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('duration_seconds');
});

it('polls the task status and returns the file_id when the video finishes', function () {
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

    // 1. Start the task — returns a task_id.
    $http->expects('request')
        ->with('POST', 'https://api.minimax.io/v1/video_generation', Mockery::any())
        ->andReturn(minimaxVideoResponse(200, json_encode([
            'base_resp' => ['status_code' => 0, 'status_msg' => 'ok'],
            'task_id'   => 'task-xyz',
        ])));

    // 2. First poll — returns "Success" (capitalized per MiniMax's enum) with
    //    file_id + dimensions. v1 returns the file_id; the actual file bytes
    //    require MiniMax's file-management API (not in v1's scope).
    $http->expects('request')
        ->with('GET', 'https://api.minimax.io/v1/query/video_generation', Mockery::on(function ($opts) {
            return ($opts['query']['task_id'] ?? null) === 'task-xyz';
        }))
        ->andReturn(minimaxVideoResponse(200, json_encode([
            'base_resp'    => ['status_code' => 0, 'status_msg' => 'success'],
            'task_id'      => 'task-xyz',
            'status'       => 'Success',
            'file_id'      => 'file-abc-123',
            'video_width'  => 1920,
            'video_height' => 1080,
        ])));

    $tool = new MiniMaxVideoTool($config, $http, $log);
    $result = $tool->execute(['prompt' => '[Push in] a forest'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('file_id: file-abc-123')
        ->and($result->content)->toContain('1920x1080')
        ->and($result->data['file_id'])->toBe('file-abc-123')
        ->and($result->data['task_id'])->toBe('task-xyz')
        ->and($result->data['width'])->toBe(1920)
        ->and($result->data['height'])->toBe(1080);
});
