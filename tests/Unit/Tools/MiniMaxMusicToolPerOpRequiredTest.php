<?php

declare(strict_types=1);

use Spora\Plugins\MiniMax\Tools\MiniMaxMusicTool;
use Spora\Tools\Attributes\ToolParameter;

/**
 * Per-op `required[]` binding tests for MiniMaxMusicTool.
 *
 * Reads `#[ToolParameter]` constructor arguments via reflection. Does
 * NOT instantiate the attribute, so the test is independent of the
 * bound spora-core version. Once spora-core ships the `bool|array
 * $required` signature AND the plugin bumps its dep, replace the
 * reflection with a `ToolParameterSchemaBuilder::build(MiniMaxMusicTool::class)`
 * round-trip.
 */
function musicToolParameterArgs(string $name): array
{
    $reflection = new ReflectionClass(MiniMaxMusicTool::class);
    foreach ($reflection->getAttributes(ToolParameter::class) as $attribute) {
        $args = $attribute->getArguments();
        if (($args['name'] ?? null) === $name) {
            return $args;
        }
    }

    throw new RuntimeException("ToolParameter '{$name}' not declared on " . MiniMaxMusicTool::class);
}

it('binds prompt and lyrics to the edit_lyrics op', function () {
    expect(musicToolParameterArgs('prompt')['required'])->toBe(['edit_lyrics']);
    expect(musicToolParameterArgs('lyrics')['required'])->toBe(['edit_lyrics']);
});

it('keeps output_format and filename at required: false', function () {
    expect(musicToolParameterArgs('output_format')['required'] ?? false)->toBeFalse();
    expect(musicToolParameterArgs('filename')['required'] ?? false)->toBeFalse();
});
