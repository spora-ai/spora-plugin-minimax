<?php

declare(strict_types=1);

use Spora\Plugins\MiniMax\MiniMaxTranscribeProvider;
use Spora\Services\ToolConfigService;
use Spora\Speech\InvalidAudioException;
use Spora\Speech\SpeechToTextException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @return array{0: MiniMaxTranscribeProvider, 1: MockResponse}
 */
function buildTranscribeProvider(array $settings, int $status = 200, ?string $body = null): array
{
    $config = Mockery::mock(ToolConfigService::class);
    $config->shouldReceive('getEffectiveSettings')->andReturn($settings);

    if ($body === null) {
        $body = json_encode(['text' => 'hello world', 'duration' => 1.5, 'trace_id' => 't-1']);
    }

    $response = new MockResponse($body, ['http_code' => $status]);
    $client   = new MockHttpClient([$response]);
    return [new MiniMaxTranscribeProvider($client, $config), $response];
}

test('isConfigured() is optimistic', function (): void {
    [$provider] = buildTranscribeProvider([]);
    expect($provider->isConfigured())->toBeTrue();
});

test('getName / getDisplayName surface the MiniMax identity', function (): void {
    [$provider] = buildTranscribeProvider([]);
    expect($provider->getName())->toBe('minimax')
        ->and($provider->getDisplayName())->toBe('MiniMax Speech-to-Text');
});

test('bindLabel() overrides getDisplayName() until reset', function (): void {
    // Mirrors what SpeechToTextRegistry::describeGeneric() does for any
    // class-level provider that opts into the bindLabel() hook — the
    // resolved effective `display_name` ToolSetting is bound before
    // getDisplayName() is read.
    [$provider] = buildTranscribeProvider([]);
    $provider->bindLabel('Agent STT');
    expect($provider->getDisplayName())->toBe('Agent STT');

    $provider->bindLabel('Group STT');
    expect($provider->getDisplayName())->toBe('Group STT');
});

test('getDisplayName() falls back to the class default when bindLabel() never fires', function (): void {
    [$provider] = buildTranscribeProvider([]);
    expect($provider->getDisplayName())->toBe('MiniMax Speech-to-Text');
});

test('transcribe() throws when api_key is empty', function (): void {
    [$provider] = buildTranscribeProvider([]);

    expect(fn() => $provider->transcribe('fake', 'audio/wav'))
        ->toThrow(SpeechToTextException::class, 'MiniMax API Key is not configured');
});

test('audio > 50 MB raises InvalidAudioException before any HTTP call', function (): void {
    [$provider] = buildTranscribeProvider(['api_key' => 'sk-test']);
    $hugeBytes = str_repeat('x', 51 * 1024 * 1024);

    expect(fn() => $provider->transcribe($hugeBytes, 'audio/wav'))
        ->toThrow(InvalidAudioException::class, 'exceeds MiniMax 50 MB file cap');
});

test('transcribe() happy path parses {text, duration, trace_id}', function (): void {
    [$provider, $response] = buildTranscribeProvider(
        ['api_key' => 'sk-test'],
        200,
        json_encode(['text' => 'How is the weather? It is raining.', 'duration' => 8.24, 'trace_id' => '021785229015510a2c883cf675b9804d']),
    );

    $result = $provider->transcribe('fake-wav-bytes', 'audio/wav');

    expect($result->text)->toBe('How is the weather? It is raining.')
        ->and($result->language)->toBeNull()
        ->and($result->durationMs)->toBe(8240.0)
        ->and($result->metadata['trace_id'])->toBe('021785229015510a2c883cf675b9804d')
        ->and($result->metadata['model'])->toBe('asr-1.0');

    expect($response->getRequestMethod())->toBe('POST')
        ->and($response->getRequestUrl())->toBe('https://api.minimax.io/v1/speech_to_text');
});

test('transcribe() posts multipart with model + file + response_format=json + Bearer header', function (): void {
    [$provider, $response] = buildTranscribeProvider(
        ['api_key' => 'sk-test'],
        200,
        json_encode(['text' => 'hi', 'duration' => 0.5, 'trace_id' => 't-2']),
    );

    $provider->transcribe('audio-bytes', 'audio/wav');

    $options = $response->getRequestOptions();
    $headersBlob = implode("\n", array_map('strval', $options['headers']));
    expect($headersBlob)->toContain('Authorization: Bearer sk-test');

    $multipart = $options['multipart'] ?? null;
    expect($multipart)->toBeArray();

    $parts = [];
    foreach ($multipart as $part) {
        $parts[$part['name']] = $part;
    }

    expect($parts['model']['contents'] ?? null)->toBe('asr-1.0')
        ->and($parts['file']['contents'] ?? null)->toBe('audio-bytes')
        ->and($parts['file']['filename'] ?? null)->toBe('audio')
        ->and($parts['file']['content_type'] ?? null)->toBe('audio/wav')
        ->and($parts['response_format']['contents'] ?? null)->toBe('json');
});

test('model ToolSetting overrides the default in the multipart body', function (): void {
    [$provider, $response] = buildTranscribeProvider(
        ['api_key' => 'sk-test', 'model' => 'asr-1.1-experimental'],
        200,
        json_encode(['text' => 'hi', 'duration' => 1.0, 'trace_id' => 't-3']),
    );

    $provider->transcribe('audio-bytes', 'audio/wav');

    $options = $response->getRequestOptions();
    $modelPart = null;
    foreach ($options['multipart'] as $part) {
        if (($part['name'] ?? null) === 'model') {
            $modelPart = $part;
        }
    }

    expect($modelPart)->not->toBeNull()
        ->and($modelPart['contents'])->toBe('asr-1.1-experimental');
});

test('empty / whitespace model setting falls back to the default model', function (): void {
    foreach (['', '   '] as $empty) {
        [$provider, $response] = buildTranscribeProvider(
            ['api_key' => 'sk-test', 'model' => $empty],
            200,
            json_encode(['text' => 'hi', 'duration' => 1.0, 'trace_id' => 't-4']),
        );

        $provider->transcribe('audio-bytes', 'audio/wav');

        $options = $response->getRequestOptions();
        $modelPart = null;
        foreach ($options['multipart'] as $part) {
            if (($part['name'] ?? null) === 'model') {
                $modelPart = $part;
            }
        }

        expect($modelPart['contents'])->toBe('asr-1.0');
    }
});

test('transcribe() sets language header when hint is non-empty', function (): void {
    [$provider, $response] = buildTranscribeProvider(
        ['api_key' => 'sk-test'],
        200,
        json_encode(['text' => 'Ming Tian', 'duration' => 1.0, 'trace_id' => 't-5']),
    );

    $result = $provider->transcribe('audio-bytes', 'audio/wav', languageHint: 'zh');

    $options = $response->getRequestOptions();
    $headersBlob = implode("\n", array_map('strval', $options['headers']));
    expect($headersBlob)->toContain('language: zh')
        ->and($result->language)->toBe('zh');
});

test('transcribe() omits language header when hint is null', function (): void {
    [$provider, $response] = buildTranscribeProvider(
        ['api_key' => 'sk-test'],
        200,
        json_encode(['text' => 'hi', 'duration' => 1.0, 'trace_id' => 't-6']),
    );

    $provider->transcribe('audio-bytes', 'audio/wav');

    $options = $response->getRequestOptions();
    $headersBlob = implode("\n", array_map('strval', $options['headers']));
    expect($headersBlob)->not->toContain('language:');
});

test('transcribe() returns InvalidAudioException on empty text', function (): void {
    [$provider] = buildTranscribeProvider(
        ['api_key' => 'sk-test'],
        200,
        json_encode(['text' => '   ', 'duration' => 0.5, 'trace_id' => 't-7']),
    );

    expect(fn() => $provider->transcribe('audio-bytes', 'audio/wav'))
        ->toThrow(InvalidAudioException::class, 'MiniMax STT returned no transcript.');
});

test('HTTP 422 sensitive-content passes upstream message through verbatim', function (): void {
    [$provider] = buildTranscribeProvider(
        ['api_key' => 'sk-test'],
        422,
        json_encode([
            'type' => 'error',
            'error' => [
                'type' => 'unprocessable_entity_error',
                'message' => 'audio content contains sensitive content (1026)',
                'http_code' => '422',
            ],
            'request_id' => 't-8',
        ]),
    );

    expect(fn() => $provider->transcribe('audio-bytes', 'audio/wav'))
        ->toThrow(SpeechToTextException::class, 'audio content contains sensitive content (1026)');
});

test('HTTP 413 returns InvalidAudioException with the cap message', function (): void {
    [$provider] = buildTranscribeProvider(
        ['api_key' => 'sk-test'],
        413,
        json_encode([
            'type' => 'error',
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'request body too large: 88200078 bytes exceeds limit of 52428800 bytes',
                'http_code' => '413',
            ],
            'request_id' => 't-9',
        ]),
    );

    expect(fn() => $provider->transcribe('audio-bytes', 'audio/wav'))
        ->toThrow(InvalidAudioException::class, 'request body too large');
});

test('HTTP 401 unauthorized_error passes upstream message through verbatim', function (): void {
    [$provider] = buildTranscribeProvider(
        ['api_key' => 'sk-test'],
        401,
        json_encode([
            'type' => 'error',
            'error' => [
                'type' => 'authorized_error',
                'message' => "login fail: Please carry the API secret key in the 'Authorization' field of the request header (1004)",
                'http_code' => '401',
            ],
            'request_id' => 't-10',
        ]),
    );

    expect(fn() => $provider->transcribe('audio-bytes', 'audio/wav'))
        ->toThrow(SpeechToTextException::class, 'login fail: Please carry the API secret key');
});

test('HTTP 500 with no parseable body returns SpeechToTextException with status', function (): void {
    [$provider] = buildTranscribeProvider(['api_key' => 'sk-test'], 500, '');

    expect(fn() => $provider->transcribe('audio-bytes', 'audio/wav'))
        ->toThrow(SpeechToTextException::class, 'MiniMax STT returned HTTP 500.');
});

test('base_url setting overrides the default endpoint', function (): void {
    [$provider, $response] = buildTranscribeProvider(
        ['api_key' => 'sk-test', 'base_url' => 'https://api.minimaxi.com'],
        200,
        json_encode(['text' => 'hi', 'duration' => 1.0, 'trace_id' => 't-11']),
    );

    $provider->transcribe('audio-bytes', 'audio/wav');

    expect($response->getRequestUrl())->toBe('https://api.minimaxi.com/v1/speech_to_text');
});

test('base_url with trailing slash is trimmed', function (): void {
    [$provider, $response] = buildTranscribeProvider(
        ['api_key' => 'sk-test', 'base_url' => 'https://api.minimaxi.com/'],
        200,
        json_encode(['text' => 'hi', 'duration' => 1.0, 'trace_id' => 't-12']),
    );

    $provider->transcribe('audio-bytes', 'audio/wav');

    expect($response->getRequestUrl())->toBe('https://api.minimaxi.com/v1/speech_to_text');
});

test('http_timeout_seconds setting overrides the default', function (): void {
    [$provider, $response] = buildTranscribeProvider(
        ['api_key' => 'sk-test', 'http_timeout_seconds' => '120'],
        200,
        json_encode(['text' => 'hi', 'duration' => 1.0, 'trace_id' => 't-13']),
    );

    $provider->transcribe('audio-bytes', 'audio/wav');

    $options = $response->getRequestOptions();
    expect((int) $options['timeout'])->toBe(120);
});

test('transcribe() includes language in result when hint was a non-empty string', function (): void {
    [$provider] = buildTranscribeProvider(
        ['api_key' => 'sk-test'],
        200,
        json_encode(['text' => 'Bonjour', 'duration' => 0.5, 'trace_id' => 't-14']),
    );

    $result = $provider->transcribe('audio-bytes', 'audio/wav', languageHint: 'fr');
    expect($result->language)->toBe('fr');
});

test('transcribe() leaves language null when hint is empty / whitespace', function (): void {
    [$provider] = buildTranscribeProvider(
        ['api_key' => 'sk-test'],
        200,
        json_encode(['text' => 'hi', 'duration' => 0.5, 'trace_id' => 't-15']),
    );

    $result = $provider->transcribe('audio-bytes', 'audio/wav', languageHint: '   ');
    expect($result->language)->toBeNull();
});

test('transcribe() leaves durationMs null when upstream omits it', function (): void {
    [$provider] = buildTranscribeProvider(
        ['api_key' => 'sk-test'],
        200,
        json_encode(['text' => 'hi', 'trace_id' => 't-16']),
    );

    $result = $provider->transcribe('audio-bytes', 'audio/wav');
    expect($result->durationMs)->toBeNull();
});
