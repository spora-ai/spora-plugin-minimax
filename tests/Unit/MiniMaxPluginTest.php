<?php

declare(strict_types=1);

use Spora\Plugins\MiniMax\MiniMaxPlugin;
use Spora\Plugins\MiniMax\Tools\MiniMaxImageTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxMusicTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxSpeechTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxVideoTool;

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
