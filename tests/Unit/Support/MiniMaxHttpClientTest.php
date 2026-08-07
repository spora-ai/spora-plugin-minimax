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

final class MiniMaxHttpClientTestLiterals
{
    public const BASE_URL = 'https://api.minimax.io';
    public const CDN_URL_PNG = 'https://example.com/a.png';
    public const PATH_X = '/v1/x';
    public const ERR_INSUFFICIENT_BALANCE = 'insufficient balance';
}

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
            'data'      => ['image_urls' => [MiniMaxHttpClientTestLiterals::CDN_URL_PNG]],
        ])));

    $client = new MiniMaxHttpClient($http, 'test-key', MiniMaxHttpClientTestLiterals::BASE_URL, 30, $logger);
    $result = $client->postJson('/v1/image_generation', ['model' => 'image-01', 'prompt' => 'a fox']);

    expect($result['data']['image_urls'][0])->toBe(MiniMaxHttpClientTestLiterals::CDN_URL_PNG)
        ->and($result['base_resp']['status_code'])->toBe(0);
});

it('throws MiniMaxApiException on HTTP 4xx', function () {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->andReturn(minimaxMockResponse(401, '{"error":"unauthorized"}'));

    $client = new MiniMaxHttpClient($http, 'bad-key', MiniMaxHttpClientTestLiterals::BASE_URL, 30);

    expect(fn() => $client->postJson(MiniMaxHttpClientTestLiterals::PATH_X, []))
        ->toThrow(MiniMaxApiException::class, 'HTTP 401');
});

it('surfaces the upstream error.message in the exception on HTTP 4xx (Anthropic-style envelope)', function () {
    // The v2 video endpoints return Anthropic-style error envelopes:
    //   {"type":"error","error":{"type":"...","message":"...","code":2013}}
    // The LLM must see the actual upstream message — not just
    // "HTTP 400" — so it can pivot to a different model / plan
    // instead of guessing. Concrete bug-fix payload (taken from
    // spora-local/storage/spora.log after the H3 migration).
    $body = json_encode([
        'type'  => 'error',
        'error' => [
            'type'    => 'bad_request_error',
            'message' => 'invalid params, TokenPlan or Credit does not currently support MiniMax-H3 series models',
            'code'    => 2013,
        ],
    ]);
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->andReturn(minimaxMockResponse(400, $body));

    $client = new MiniMaxHttpClient($http, 'k', MiniMaxHttpClientTestLiterals::BASE_URL, 30);

    $caught = null;
    try {
        $client->postJson(MiniMaxHttpClientTestLiterals::PATH_X, []);
    } catch (MiniMaxApiException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull()
        ->and($caught->statusCode)->toBe(400)
        ->and($caught->getMessage())->toContain('HTTP 400')
        ->and($caught->getMessage())->toContain('[2013]')
        ->and($caught->getMessage())->toContain('TokenPlan or Credit does not currently support MiniMax-H3 series models')
        ->and($caught->baseResp['error']['code'])->toBe(2013);
});

it('surfaces the upstream error.message in the exception on HTTP 4xx (v1 base_resp-style envelope)', function () {
    // The v1 endpoints return MiniMax-style error envelopes:
    //   {"base_resp":{"status_code":2013,"status_msg":"..."}}
    $body = json_encode([
        'base_resp' => [
            'status_code' => 2013,
            'status_msg'  => 'Invalid input parameters, please check if the parameters are filled in as required',
        ],
    ]);
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->andReturn(minimaxMockResponse(400, $body));

    $client = new MiniMaxHttpClient($http, 'k', MiniMaxHttpClientTestLiterals::BASE_URL, 30);

    expect(fn() => $client->postJson(MiniMaxHttpClientTestLiterals::PATH_X, []))
        ->toThrow(MiniMaxApiException::class, '[2013] Invalid input parameters');
});

it('falls back to the generic HTTP message when the 4xx body is non-JSON', function () {
    // Defensive: if the upstream returns a non-JSON body (network
    // blip, load balancer error page), the client must still raise
    // a meaningful exception — "" / "HTTP 400" alone is unhelpful.
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->andReturn(minimaxMockResponse(400, '<!DOCTYPE html>Bad Request</html>'));

    $client = new MiniMaxHttpClient($http, 'k', MiniMaxHttpClientTestLiterals::BASE_URL, 30);

    expect(fn() => $client->postJson(MiniMaxHttpClientTestLiterals::PATH_X, []))
        ->toThrow(MiniMaxApiException::class, 'HTTP 400');
});

it('throws MiniMaxApiException on HTTP 5xx without retrying', function () {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')->once()->andReturn(minimaxMockResponse(500, 'oops'));

    $client = new MiniMaxHttpClient($http, 'k', MiniMaxHttpClientTestLiterals::BASE_URL, 30);
    expect(fn() => $client->postJson(MiniMaxHttpClientTestLiterals::PATH_X, []))
        ->toThrow(MiniMaxApiException::class, 'HTTP 500');
});

it('throws MiniMaxApiException on HTTP 429 without retrying', function () {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')->once()->andReturn(minimaxMockResponse(429, 'rate'));

    $client = new MiniMaxHttpClient($http, 'k', MiniMaxHttpClientTestLiterals::BASE_URL, 30);
    expect(fn() => $client->postJson(MiniMaxHttpClientTestLiterals::PATH_X, []))
        ->toThrow(MiniMaxApiException::class, 'HTTP 429');
});

it('throws MiniMaxApiException on non-zero base_resp.status_code and does not retry', function () {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')->once()->andReturn(minimaxMockResponse(200, json_encode([
        'base_resp' => ['status_code' => 1008, 'status_msg' => MiniMaxHttpClientTestLiterals::ERR_INSUFFICIENT_BALANCE],
    ])));

    $client = new MiniMaxHttpClient($http, 'k', MiniMaxHttpClientTestLiterals::BASE_URL, 30);

    $caught = null;
    try {
        $client->postJson(MiniMaxHttpClientTestLiterals::PATH_X, []);
    } catch (MiniMaxApiException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull()
        ->and($caught->statusCode)->toBe(1008)
        ->and($caught->getMessage())->toContain(MiniMaxHttpClientTestLiterals::ERR_INSUFFICIENT_BALANCE)
        ->and($caught->baseResp['status_msg'])->toBe(MiniMaxHttpClientTestLiterals::ERR_INSUFFICIENT_BALANCE);
});

it('does not retry on transport errors and surfaces them as MiniMaxApiException', function () {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')->once()->andThrow(new TestableTransportException('connection failed'));

    $client = new MiniMaxHttpClient($http, 'k', MiniMaxHttpClientTestLiterals::BASE_URL, 30);
    expect(fn() => $client->postJson(MiniMaxHttpClientTestLiterals::PATH_X, []))
        ->toThrow(MiniMaxApiException::class, 'MiniMax API request failed');
});

it('does not retry on 5xx and surfaces the HTTP status', function () {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')->once()->andReturn(minimaxMockResponse(502, 'bad gateway'));

    $client = new MiniMaxHttpClient($http, 'k', MiniMaxHttpClientTestLiterals::BASE_URL, 30);
    expect(fn() => $client->postJson(MiniMaxHttpClientTestLiterals::PATH_X, []))
        ->toThrow(MiniMaxApiException::class, 'HTTP 502');
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

    $client = new MiniMaxHttpClient($http, 'k', MiniMaxHttpClientTestLiterals::BASE_URL, 30);
    $result = $client->getJson('/v1/query/video_generation', ['task_id' => 'task-abc']);
    expect($result['status'])->toBe('processing');
});
