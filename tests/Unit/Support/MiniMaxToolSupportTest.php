<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax\Tests\Unit\Support;

use Mockery;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Spora\Plugins\MiniMax\Support\Exceptions\MiniMaxApiException;
use Spora\Plugins\MiniMax\Support\MiniMaxHttpClient;
use Spora\Plugins\MiniMax\Support\MiniMaxLogWriter;
use Spora\Plugins\MiniMax\Support\MiniMaxToolContext;
use Spora\Plugins\MiniMax\Support\MiniMaxToolSupport;
use Spora\Plugins\MiniMax\Tools\MiniMaxImageTool;
use Spora\Services\ToolConfigService;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

it('returns a failure ToolResult from prepare() when the API key setting is missing', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([]);

    $http   = Mockery::mock(HttpClientInterface::class);
    $writer = new MiniMaxLogWriter();
    $support = new MiniMaxToolSupport($config, $http, $writer);

    $result = $support->prepare(
        toolClass: MiniMaxImageTool::class,
        provider: 'image',
        qualifiedName: 'minimax:image',
        arguments: ['prompt' => 'a fox'],
        agentId: 1,
        userId: null,
        timeoutSeconds: 30,
    );

    expect($result)->toBeInstanceOf(ToolResult::class)
        ->and($result->success)->toBeFalse()
        ->and($result->content)->toContain('API key is not configured');
});

it('returns a failure ToolResult from run() when the work callable throws MiniMaxApiException', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['plugin.minimax.image.api_key' => 'k']);

    // Real (non-mockable) dependencies — only the result matters here.
    $http   = Mockery::mock(HttpClientInterface::class);
    $writer = new MiniMaxLogWriter();
    $support = new MiniMaxToolSupport($config, $http, $writer);
    $ctx = new MiniMaxToolContext(
        provider: 'image',
        qualifiedName: 'minimax:image',
        client: new MiniMaxHttpClient($http, 'k', 'https://api.minimax.io', 30),
        settings: ['plugin.minimax.image.api_key' => 'k'],
        arguments: ['prompt' => 'a fox'],
        userId: 7,
        agentId: 1,
    );

    $result = $support->run($ctx, 'Image generation', function () {
        throw new MiniMaxApiException('MiniMax 401', 401);
    });

    expect($result)->toBeInstanceOf(ToolResult::class)
        ->and($result->success)->toBeFalse()
        ->and($result->content)->toContain('MiniMax 401');
});

it('returns a failure ToolResult from run() when the work callable throws an arbitrary Throwable', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['plugin.minimax.image.api_key' => 'k']);

    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('error')->once();

    $http   = Mockery::mock(HttpClientInterface::class);
    $writer = new MiniMaxLogWriter();
    $support = new MiniMaxToolSupport($config, $http, $writer, $logger);
    $ctx = new MiniMaxToolContext(
        provider: 'image',
        qualifiedName: 'minimax:image',
        client: new MiniMaxHttpClient($http, 'k', 'https://api.minimax.io', 30),
        settings: ['plugin.minimax.image.api_key' => 'k'],
        arguments: ['prompt' => 'a fox'],
        userId: 7,
        agentId: 1,
    );

    $result = $support->run($ctx, 'Image generation', function () {
        throw new RuntimeException('boom');
    });

    expect($result)->toBeInstanceOf(ToolResult::class)
        ->and($result->success)->toBeFalse()
        ->and($result->content)->toContain('Image generation failed: boom');
});

it('returns the work callable result from run() on the happy path', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['plugin.minimax.image.api_key' => 'k']);

    $http   = Mockery::mock(HttpClientInterface::class);
    $writer = new MiniMaxLogWriter();
    $support = new MiniMaxToolSupport($config, $http, $writer);
    $ctx = new MiniMaxToolContext(
        provider: 'image',
        qualifiedName: 'minimax:image',
        client: new MiniMaxHttpClient($http, 'k', 'https://api.minimax.io', 30),
        settings: ['plugin.minimax.image.api_key' => 'k'],
        arguments: ['prompt' => 'a fox'],
        userId: 7,
        agentId: 1,
    );

    $result = $support->run($ctx, 'Image generation', fn() => new ToolResult(true, 'ok'));

    expect($result->success)->toBeTrue()
        ->and($result->content)->toBe('ok');
});
