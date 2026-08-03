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
 * Regression coverage for the production logger wiring. PHP-DI's
 * reflection autowiring leaves nullable ctor params at their default
 * value (null) — without an explicit binding the support's logger is
 * always null and every `?->warning()` call in the four MiniMax tools
 * is a silent no-op. These tests pin both the ctor path and the
 * setter path that {@see \Spora\Plugins\MiniMax\MiniMaxPlugin::register()}
 * uses at boot.
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

    // No ctor logger — mirrors the production wire path. PHP-DI
    // construction leaves the optional ctor param at its default
    // (null); the production logger only reaches the support through
    // setLogger().
    $support = new MiniMaxToolSupport($config, $http, $writer);
    expect($support->logger())->toBeNull();

    $logger = Mockery::mock(LoggerInterface::class);
    $support->setLogger($logger);

    expect($support->logger())->toBe($logger);
});

it('setLogger() overwrites a logger passed to the constructor', function () {
    // Production wires the logger twice: the ctor sees null (PHP-DI
    // skip), the setter sees the real Monolog instance. The setter is
    // authoritative — a stale ctor value must not survive.
    $config = Mockery::mock(ToolConfigService::class);
    $http   = Mockery::mock(HttpClientInterface::class);
    $writer = new MiniMaxLogWriter();

    $first  = Mockery::mock(LoggerInterface::class);
    $second = Mockery::mock(LoggerInterface::class);

    $support = new MiniMaxToolSupport($config, $http, $writer, $first);
    $support->setLogger($second);

    expect($support->logger())->toBe($second);
});
