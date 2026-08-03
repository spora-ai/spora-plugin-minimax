<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax\Tests\Unit\Support;

use Mockery;
use Psr\Log\LoggerInterface;
use Spora\Plugins\MiniMax\Support\MiniMaxLogWriter;
use Spora\Plugins\MiniMax\Support\MiniMaxToolSupport;
use Spora\Services\ToolConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Pins the support's ctor + setter paths so PHP-DI's nullable-ctor
 * short-circuit can't silently regress the production logger wire.
 */
it('exposes the logger passed to the constructor through logger()', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $http   = Mockery::mock(HttpClientInterface::class);
    $writer = new MiniMaxLogWriter();
    $logger = Mockery::mock(LoggerInterface::class);

    $support = new MiniMaxToolSupport($config, $http, $writer, $logger);

    expect($support->logger())->toBe($logger);
});

it('exposes the logger set via setLogger() through logger()', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $http   = Mockery::mock(HttpClientInterface::class);
    $writer = new MiniMaxLogWriter();

    $support = new MiniMaxToolSupport($config, $http, $writer);
    expect($support->logger())->toBeNull();

    $logger = Mockery::mock(LoggerInterface::class);
    $support->setLogger($logger);

    expect($support->logger())->toBe($logger);
});

it('setLogger() overwrites a logger passed to the constructor', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $http   = Mockery::mock(HttpClientInterface::class);
    $writer = new MiniMaxLogWriter();

    $first  = Mockery::mock(LoggerInterface::class);
    $second = Mockery::mock(LoggerInterface::class);

    $support = new MiniMaxToolSupport($config, $http, $writer, $first);
    $support->setLogger($second);

    expect($support->logger())->toBe($second);
});
