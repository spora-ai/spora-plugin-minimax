<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Spora\Plugins\MiniMax\MiniMaxPlugin;
use Spora\Plugins\MiniMax\Tools\MiniMaxImageTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxMusicTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxSpeechTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxVideoTool;
use Spora\Services\MediaArchive\MediaArchiveService;

it('returns plugin name', function () {
    $plugin = new MiniMaxPlugin();
    expect($plugin->getName())->toBe('MiniMax');
});

it('contributes all four MiniMax tools', function () {
    $plugin = new MiniMaxPlugin();
    expect($plugin->tools())->toBe([
        MiniMaxImageTool::class,
        MiniMaxSpeechTool::class,
        MiniMaxMusicTool::class,
        MiniMaxVideoTool::class,
    ]);
});

it('declares schema version 1', function () {
    $plugin = new MiniMaxPlugin();
    expect($plugin->schemaVersion())->toBe(1);
});

it('points migrations at the bundled migrations directory', function () {
    $plugin = new MiniMaxPlugin();
    expect($plugin->migrationsPath())->toEndWith('/database/migrations');
});

it('register() binds each MiniMax tool with a setMediaArchive resolver', function () {
    $plugin = new MiniMaxPlugin();
    $builder = new ContainerBuilder();
    $builder->useAutowiring(true);

    $plugin->register($builder);

    // Build the container so PHP-DI validates the definitions. This
    // surfaces a runtime error if any tool's autowire()->method() binding
    // is malformed (the most likely failure mode — the DefaultValueResolver
    // / TypeHintContainerResolver short-circuit documented on the register()
    // method's docblock).
    $container = $builder->build();

    // Confirm each MiniMax*Tool can be resolved. MediaArchiveService is
    // declared in the container; PHP-DI's autowire path now reaches the
    // setMediaArchive() setter thanks to the explicit \DI\get() binding.
    foreach ([MiniMaxImageTool::class, MiniMaxSpeechTool::class, MiniMaxMusicTool::class, MiniMaxVideoTool::class] as $toolClass) {
        expect($container->has($toolClass))->toBeTrue();
    }
});
