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
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http = Mockery::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();

    $tool = new MiniMaxVideoTool($config, $http, $log);

    $result = $tool->execute(['prompt' => 'a forest', 'duration_seconds' => '30'], 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('duration_seconds');
});

it('polls the task status, calls file-retrieve, and embeds the download URL', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key'                  => 'k',
        // Poll quickly: interval=1s, timeout=5s — the happy path doesn't wait
        // long, but the loop still has a real deadline.
        'poll_interval_seconds'    => '1',
        'poll_timeout_seconds'     => '5',
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

    // 2. Poll — returns "Success" with file_id + dimensions.
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

    // 3. File retrieve — returns the download URL valid for 1 hour.
    $http->expects('request')
        ->with('GET', 'https://api.minimax.io/v1/files/retrieve', Mockery::on(function ($opts) {
            return ($opts['query']['file_id'] ?? null) === 'file-abc-123';
        }))
        ->andReturn(minimaxVideoResponse(200, json_encode([
            'file' => [
                'file_id'      => 'file-abc-123',
                'bytes'        => 5_896_337,
                'filename'     => 'output_aigc.mp4',
                'purpose'      => 'video_generation',
                'download_url' => 'https://minimax.example/output.mp4',
            ],
            'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
        ])));

    $tool = new MiniMaxVideoTool($config, $http, $log);
    $result = $tool->execute(['prompt' => '[Push in] a forest'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('<video')
        ->and($result->content)->toContain('https://minimax.example/output.mp4')
        ->and($result->content)->toContain('width="1920"')
        ->and($result->content)->toContain('height="1080"')
        ->and($result->content)->toContain('file_id: file-abc-123')
        ->and($result->content)->toContain('1 hour')
        ->and($result->data['file_id'])->toBe('file-abc-123')
        ->and($result->data['task_id'])->toBe('task-xyz')
        ->and($result->data['download_url'])->toBe('https://minimax.example/output.mp4')
        ->and($result->data['width'])->toBe(1920)
        ->and($result->data['height'])->toBe(1080);
});

it('returns a failure when file-retrieve omits the download URL', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key'               => 'k',
        'poll_interval_seconds' => '1',
        'poll_timeout_seconds'  => '5',
    ]);

    $http = Mockery::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();

    $http->allows('request')
        ->with('POST', 'https://api.minimax.io/v1/video_generation', Mockery::any())
        ->andReturn(minimaxVideoResponse(200, json_encode([
            'base_resp' => ['status_code' => 0, 'status_msg' => 'ok'],
            'task_id'   => 'task-xyz',
        ])));
    $http->allows('request')
        ->with('GET', 'https://api.minimax.io/v1/query/video_generation', Mockery::any())
        ->andReturn(minimaxVideoResponse(200, json_encode([
            'base_resp'    => ['status_code' => 0, 'status_msg' => 'success'],
            'task_id'      => 'task-xyz',
            'status'       => 'Success',
            'file_id'      => 'file-abc-123',
            'video_width'  => 1920,
            'video_height' => 1080,
        ])));
    $http->allows('request')
        ->with('GET', 'https://api.minimax.io/v1/files/retrieve', Mockery::any())
        ->andReturn(minimaxVideoResponse(200, json_encode([
            'file'      => ['file_id' => 'file-abc-123'],
            'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
        ])));

    $tool = new MiniMaxVideoTool($config, $http, $log);
    $result = $tool->execute(['prompt' => '[Push in] a forest'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('did not return a download_url');
});
