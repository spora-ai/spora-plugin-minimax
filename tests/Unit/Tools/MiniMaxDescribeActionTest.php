<?php

declare(strict_types=1);

use Mockery as M;
use Spora\Plugins\MiniMax\Tools\MiniMaxImageTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxMusicTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxSpeechTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxVideoTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxVideoV1Tool;
use Spora\Services\AssetStore;
use Spora\Services\ToolConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The approval prompts the tools render into the chat bubble.
 *
 * A catalogue key IS the English `sprintf()` template — the host looks the
 * rendered sentence up and matches it against the catalogue's templates. A
 * copy edit that changes the template orphans the German translation
 * silently, so every prompt asserted here is also asserted against
 * `locales/de.json`.
 */

$catalogPath = dirname(__DIR__, 3) . '/locales/de.json';

$germanCatalogue = static function () use ($catalogPath): array {
    return (array) json_decode((string) file_get_contents($catalogPath), true, 512, JSON_THROW_ON_ERROR);
};

it('describes the image generation approval prompt', function (array $arguments, string $catalogueKey, string $expected) use ($germanCatalogue) {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([]);

    $http = M::mock(HttpClientInterface::class);

    $tool = new MiniMaxImageTool($config, $http);

    expect($tool->describeAction($arguments))->toBe($expected)
        ->and($germanCatalogue())->toHaveKey($catalogueKey);
})->with([
    'generate' => [
        ['prompt' => 'a red fox'],
        "Generate image for prompt: '%s'",
        "Generate image for prompt: 'a red fox'",
    ],
]);

it('describes the music approval prompt per operation', function (array $arguments, string $catalogueKey, string $expected) use ($germanCatalogue) {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([]);

    $http = M::mock(HttpClientInterface::class);

    $tool = new MiniMaxMusicTool($config, $http, M::mock(AssetStore::class));

    expect($tool->describeAction($arguments))->toBe($expected)
        ->and($germanCatalogue())->toHaveKey($catalogueKey);
})->with([
    'compose' => [
        ['action' => 'compose', 'prompt' => 'a sunny day'],
        "Generate music for: '%s'",
        "Generate music for: 'a sunny day'",
    ],
    'compose without an action falls back to the first declared operation' => [
        ['prompt' => 'a sunny day'],
        "Generate music for: '%s'",
        "Generate music for: 'a sunny day'",
    ],
    'write_lyrics' => [
        ['action' => 'write_lyrics', 'prompt' => 'a song about the sea'],
        "Write song lyrics for: '%s'",
        "Write song lyrics for: 'a song about the sea'",
    ],
    'edit_lyrics' => [
        ['action' => 'edit_lyrics', 'prompt' => 'make it sadder'],
        "Edit song lyrics: '%s'",
        "Edit song lyrics: 'make it sadder'",
    ],
]);

it('describes the speech approval prompt per operation', function (array $arguments, string $catalogueKey, string $expected) use ($germanCatalogue) {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([]);

    $http = M::mock(HttpClientInterface::class);

    $tool = new MiniMaxSpeechTool($config, $http, M::mock(AssetStore::class));

    expect($tool->describeAction($arguments))->toBe($expected)
        ->and($germanCatalogue())->toHaveKey($catalogueKey);
})->with([
    'synthesize' => [
        ['action' => 'synthesize', 'text' => 'Hello world'],
        "Synthesize speech for: '%s'",
        "Synthesize speech for: 'Hello world'",
    ],
    'synthesize without an action falls back to the synthesizer' => [
        ['text' => 'Hello world'],
        "Synthesize speech for: '%s'",
        "Synthesize speech for: 'Hello world'",
    ],
    'voices' => [
        ['action' => 'voices'],
        'Fetch the MiniMax voice library',
        'Fetch the MiniMax voice library',
    ],
]);

it('describes the H3 video approval prompt per operation', function (array $arguments, string $catalogueKey, string $expected) use ($germanCatalogue) {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([]);

    $http = M::mock(HttpClientInterface::class);

    $tool = new MiniMaxVideoTool($config, $http);

    expect($tool->describeAction($arguments))->toBe($expected)
        ->and($germanCatalogue())->toHaveKey($catalogueKey);
})->with([
    'generate' => [
        ['action' => 'generate', 'prompt' => 'a forest'],
        "Generate video for prompt: '%s'",
        "Generate video for prompt: 'a forest'",
    ],
    'generate without an action falls back to the first declared operation' => [
        ['prompt' => 'a forest'],
        "Generate video for prompt: '%s'",
        "Generate video for prompt: 'a forest'",
    ],
    'enhance_prompt' => [
        ['action' => 'enhance_prompt', 'prompt' => 'a forest'],
        "Enhance H3 prompt for: '%s'",
        "Enhance H3 prompt for: 'a forest'",
    ],
    'regenerate' => [
        ['action' => 'regenerate', 'task_id' => 'task-123'],
        "Regenerate H3 video at 2K for task: '%s'",
        "Regenerate H3 video at 2K for task: 'task-123'",
    ],
    'resume' => [
        ['action' => 'resume', 'task_id' => 'task-123'],
        "Resume video polling for task: '%s'",
        "Resume video polling for task: 'task-123'",
    ],
]);

it('describes the v1 video approval prompt per operation', function (array $arguments, string $catalogueKey, string $expected) use ($germanCatalogue) {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([]);

    $http = M::mock(HttpClientInterface::class);

    $tool = new MiniMaxVideoV1Tool($config, $http);

    expect($tool->describeAction($arguments))->toBe($expected)
        ->and($germanCatalogue())->toHaveKey($catalogueKey);
})->with([
    'generate' => [
        ['action' => 'generate', 'prompt' => 'a forest'],
        "Generate v1 video for prompt: '%s'",
        "Generate v1 video for prompt: 'a forest'",
    ],
    'generate without an action falls back to the first declared operation' => [
        ['prompt' => 'a forest'],
        "Generate v1 video for prompt: '%s'",
        "Generate v1 video for prompt: 'a forest'",
    ],
    'resume' => [
        ['action' => 'resume', 'task_id' => 'task-123'],
        "Resume v1 video polling for task: '%s'",
        "Resume v1 video polling for task: 'task-123'",
    ],
]);

/**
 * The host matches the rendered sentence against the catalogue's `%s`
 * templates with a `(.+?)` pattern and no DOTALL modifier, so an argument
 * holding a newline never matched its template: `trans()` fell back to
 * English and a German operator approved an English prompt. The `s`
 * modifier lands in core, but the released core ships no translation
 * layer, so this plugin cannot exercise the host's matcher and asserts
 * only its own half — the prompt renders from a template the catalogue
 * carries, the German template carries the same placeholders, and the
 * multi-line argument reaches the slot unchanged.
 *
 * Once core ships the DOTALL fix, assert the German sentence too: the
 * host's `Translator::trans()` fills the German template.
 */
it('carries a multi-line prompt into the image approval prompt template the catalogue carries', function () use ($germanCatalogue) {
    $catalogue = $germanCatalogue();
    $template = "Generate image for prompt: '%s'";
    $german = $catalogue[$template] ?? '';
    $prompt = "a red fox\nin the snow";

    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([]);

    $tool = new MiniMaxImageTool($config, M::mock(HttpClientInterface::class));

    expect($catalogue)->toHaveKey($template)
        ->and($german)->not->toBe('')
        ->and($tool->describeAction(['prompt' => $prompt]))->toBe(sprintf($template, $prompt));

    preg_match_all('/%[sd]/', $template, $english);
    preg_match_all('/%[sd]/', $german, $germanSpecifiers);

    expect($germanSpecifiers[0])->toBe($english[0]);
});
