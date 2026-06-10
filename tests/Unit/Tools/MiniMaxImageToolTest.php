<?php

declare(strict_types=1);

use Spora\Plugins\MiniMax\Support\MiniMaxLogWriter;
use Spora\Plugins\MiniMax\Tools\MiniMaxImageTool;
use Spora\Services\ToolConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

function minimaxImageResponse(int $status, string $body): ResponseInterface
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

    $tool = new MiniMaxImageTool($config, $http, $log);

    $result = $tool->execute(['prompt' => 'a red fox'], 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('API key is not configured');
});

it('returns an error when the prompt is empty', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['plugin.minimax.image.api_key' => 'k']);

    $http = Mockery::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();

    $tool = new MiniMaxImageTool($config, $http, $log);

    $result = $tool->execute(['prompt' => '   '], 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Prompt cannot be empty');
});

it('makes a POST to /v1/image_generation and parses the image URLs on success', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['plugin.minimax.image.api_key' => 'k']);

    $http = Mockery::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();

    $http->expects('request')
        ->with('POST', 'https://api.minimax.io/v1/image_generation', Mockery::on(function ($opts) {
            return ($opts['json']['prompt'] ?? null) === 'a red fox'
                && ($opts['json']['model'] ?? null) === 'image-01'
                && ($opts['headers']['Authorization'] ?? null) === 'Bearer k';
        }))
        ->andReturn(minimaxImageResponse(200, json_encode([
            'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
            'data'      => ['image_urls' => ['https://cdn.example.com/a.png']],
        ])));

    $tool = new MiniMaxImageTool($config, $http, $log);
    $result = $tool->execute(['prompt' => 'a red fox'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('https://cdn.example.com/a.png')
        ->and($result->data['image_urls'][0])->toBe('https://cdn.example.com/a.png');
});

it('surfaces a business-error message when base_resp.status_code is non-zero', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['plugin.minimax.image.api_key' => 'k']);

    $http = Mockery::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();

    $http->expects('request')
        ->andReturn(minimaxImageResponse(200, json_encode([
            'base_resp' => ['status_code' => 1008, 'status_msg' => 'insufficient balance'],
        ])));

    $tool = new MiniMaxImageTool($config, $http, $log);
    $result = $tool->execute(['prompt' => 'a red fox'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('1008')
        ->and($result->content)->toContain('insufficient balance');
});

it('returns a failure when the response contains no image URLs', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['plugin.minimax.image.api_key' => 'k']);

    $http = Mockery::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();

    $http->expects('request')
        ->andReturn(minimaxImageResponse(200, json_encode([
            'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
            'data'      => ['image_urls' => []],
        ])));

    $tool = new MiniMaxImageTool($config, $http, $log);
    $result = $tool->execute(['prompt' => 'a red fox'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('no image URLs');
});
