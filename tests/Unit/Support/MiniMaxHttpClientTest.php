<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax\Tests\Unit\Support;

use Mockery;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Spora\Plugins\MiniMax\Support\Exceptions\MiniMaxApiException;
use Spora\Plugins\MiniMax\Support\MiniMaxHttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Real Throwable + TransportExceptionInterface so the test can `throw` it.
 * Mockery::mock() on TransportExceptionInterface produces a non-Throwable
 * object that PHP's `throw` rejects.
 */
final class TestableTransportException extends RuntimeException implements TransportExceptionInterface {}

function minimaxMockResponse(int $statusCode, string $body): ResponseInterface
{
    $response = Mockery::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn($statusCode);
    $response->allows('getContent')->andReturn($body);
    if ($statusCode >= 200 && $statusCode < 300) {
        $decoded = json_decode($body, true);
        $response->allows('toArray')->andReturn($decoded);
    }
    return $response;
}

it('returns decoded JSON on a 2xx response with base_resp.status_code = 0', function () {
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldIgnoreMissing();

    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://api.minimax.io/v1/image_generation', Mockery::on(function ($opts) {
            return $opts['headers']['Authorization'] === 'Bearer test-key'
                && $opts['headers']['Content-Type'] === 'application/json'
                && $opts['json']['model'] === 'image-01';
        }))
        ->andReturn(minimaxMockResponse(200, json_encode([
            'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
            'data'      => ['image_urls' => ['https://example.com/a.png']],
        ])));

    $client = new MiniMaxHttpClient($http, 'test-key', 'https://api.minimax.io', 30, $logger);
    $result = $client->postJson('/v1/image_generation', ['model' => 'image-01', 'prompt' => 'a fox']);

    expect($result['data']['image_urls'][0])->toBe('https://example.com/a.png')
        ->and($result['base_resp']['status_code'])->toBe(0);
});

it('throws MiniMaxApiException on HTTP 4xx', function () {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->andReturn(minimaxMockResponse(401, '{"error":"unauthorized"}'));

    $client = new MiniMaxHttpClient($http, 'bad-key', 'https://api.minimax.io', 30);

    expect(fn() => $client->postJson('/v1/x', []))
        ->toThrow(MiniMaxApiException::class, 'HTTP 401');
});

it('throws MiniMaxApiException on HTTP 5xx after retries are exhausted', function () {
    $http = Mockery::mock(HttpClientInterface::class);
    // 3 attempts (initial + 2 retries) — all 500
    $http->expects('request')->times(3)->andReturn(minimaxMockResponse(500, 'oops'));

    $client = new MiniMaxHttpClient($http, 'k', 'https://api.minimax.io', 30);
    expect(fn() => $client->postJson('/v1/x', []))
        ->toThrow(MiniMaxApiException::class, 'HTTP 500');
});

it('retries on HTTP 429 then succeeds', function () {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')->twice()->andReturnValues([
        minimaxMockResponse(429, 'rate'),
        minimaxMockResponse(200, json_encode([
            'base_resp' => ['status_code' => 0, 'status_msg' => 'ok'],
            'data'      => ['image_urls' => ['https://example.com/a.png']],
        ])),
    ]);

    $client = new MiniMaxHttpClient($http, 'k', 'https://api.minimax.io', 30);
    $result = $client->postJson('/v1/x', []);
    expect($result['data']['image_urls'][0])->toBe('https://example.com/a.png');
});

it('throws MiniMaxApiException on non-zero base_resp.status_code and does not retry', function () {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')->once()->andReturn(minimaxMockResponse(200, json_encode([
        'base_resp' => ['status_code' => 1008, 'status_msg' => 'insufficient balance'],
    ])));

    $client = new MiniMaxHttpClient($http, 'k', 'https://api.minimax.io', 30);

    try {
        $client->postJson('/v1/x', []);
        $this->fail('Expected MiniMaxApiException');
    } catch (MiniMaxApiException $e) {
        expect($e->statusCode)->toBe(1008)
            ->and($e->getMessage())->toContain('insufficient balance')
            ->and($e->baseResp['status_msg'])->toBe('insufficient balance');
    }
});

it('retries on transport errors then succeeds', function () {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')->twice()->andReturnUsing(function () {
        static $count = 0;
        $count++;
        if ($count === 1) {
            throw new TestableTransportException('connection reset');
        }
        return minimaxMockResponse(200, json_encode([
            'base_resp' => ['status_code' => 0, 'status_msg' => 'ok'],
            'data'      => ['image_urls' => ['https://example.com/a.png']],
        ]));
    });

    $client = new MiniMaxHttpClient($http, 'k', 'https://api.minimax.io', 30);
    $result = $client->postJson('/v1/x', []);
    expect($result['data']['image_urls'][0])->toBe('https://example.com/a.png');
});

it('throws MiniMaxApiException when transport errors exceed the retry budget', function () {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')->times(3)->andThrow(new TestableTransportException('connection failed'));

    $client = new MiniMaxHttpClient($http, 'k', 'https://api.minimax.io', 30);
    expect(fn() => $client->postJson('/v1/x', []))
        ->toThrow(MiniMaxApiException::class, 'MiniMax API request failed');
});

it('appends query parameters to GET requests', function () {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('GET', 'https://api.minimax.io/v1/query/video_generation', Mockery::on(function ($opts) {
            return ($opts['query']['task_id'] ?? null) === 'task-abc'
                && $opts['headers']['Authorization'] === 'Bearer k';
        }))
        ->andReturn(minimaxMockResponse(200, json_encode([
            'base_resp' => ['status_code' => 0, 'status_msg' => 'ok'],
            'status'    => 'processing',
        ])));

    $client = new MiniMaxHttpClient($http, 'k', 'https://api.minimax.io', 30);
    $result = $client->getJson('/v1/query/video_generation', ['task_id' => 'task-abc']);
    expect($result['status'])->toBe('processing');
});
