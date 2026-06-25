<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax\Support;

use Psr\Log\LoggerInterface;
use Spora\Plugins\MiniMax\Support\Exceptions\MiniMaxApiException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Thin wrapper over Symfony's HttpClientInterface that knows the MiniMax envelope.
 *
 * - Adds `Authorization: Bearer <api_key>` to every request.
 * - Retries 2x on HTTP 429 and 5xx with exponential backoff (250ms, 750ms).
 * - Raises MiniMaxApiException on:
 *     - transport failure (after retries are exhausted), or
 *     - HTTP >= 400, or
 *     - HTTP 200 with `base_resp.status_code != 0` (the MiniMax business-error envelope).
 *
 * Note: this wrapper does NOT pass `max_size` to the underlying client — that's
 * only accepted by Symfony's `RetryableHttpClient`, and the container injects
 * a raw `CurlHttpClient`. Callers that need a response-size cap should wrap the
 * client themselves before passing it in.
 */
final class MiniMaxHttpClient
{
    /** HTTP status codes that get retried. Business errors in `base_resp` are NOT retried. */
    private const RETRYABLE_HTTP_CODES = [429, 500, 502, 503, 504];

    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds = 30,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * @param  array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function postJson(string $path, array $body, int $timeoutSeconds = 0): array
    {
        return $this->request('POST', $path, ['json' => $body], $timeoutSeconds);
    }

    /**
     * @param  array<string, scalar|null> $query
     * @return array<string, mixed>
     */
    public function getJson(string $path, array $query = [], int $timeoutSeconds = 0): array
    {
        $options = $query === [] ? [] : ['query' => $query];
        return $this->request('GET', $path, $options, $timeoutSeconds);
    }

    /**
     * @param  array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $options, int $overrideTimeout): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        $timeout = $overrideTimeout > 0 ? $overrideTimeout : $this->timeoutSeconds;
        $requestOptions = $this->buildRequestOptions($options, $timeout);

        $attempt = 0;

        while (true) {
            $attempt++;
            $this->logger?->debug('MiniMaxHttpClient: request', [
                'method'  => $method,
                'url'     => $url,
                'attempt' => $attempt,
                'timeout' => $timeout,
            ]);

            try {
                $response = $this->httpClient->request($method, $url, $requestOptions);
                $statusCode = $response->getStatusCode();

                if ($this->isRetryable($statusCode, $attempt)) {
                    $this->logger?->warning('MiniMaxHttpClient: retryable HTTP status, retrying', [
                        'url'     => $url,
                        'status'  => $statusCode,
                        'attempt' => $attempt,
                    ]);
                    usleep($this->backoffMicroseconds($attempt));
                    continue;
                }

                return $this->decodeResponse($response->getContent(false), $statusCode, $url);
            } catch (TransportExceptionInterface $e) {
                if ($attempt < self::MAX_ATTEMPTS) {
                    $this->logger?->warning('MiniMaxHttpClient: transport error, retrying', [
                        'url'     => $url,
                        'attempt' => $attempt,
                        'error'   => $e->getMessage(),
                    ]);
                    usleep($this->backoffMicroseconds($attempt));
                    continue;
                }
                $this->logger?->error('MiniMaxHttpClient: transport error, giving up', [
                    'url'   => $url,
                    'error' => $e->getMessage(),
                ]);
                throw new MiniMaxApiException(
                    'MiniMax API request failed: ' . $e->getMessage(),
                    0,
                );
            } catch (MiniMaxApiException $e) {
                throw $e;
            } catch (Throwable $e) {
                // Decoding or any other unexpected error — surface as MiniMaxApiException
                // so callers only need to catch one type.
                throw new MiniMaxApiException(
                    'MiniMax API request failed: ' . $e->getMessage(),
                    0,
                );
            }
        }
    }

    /**
     * @param  array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function buildRequestOptions(array $options, int $timeout): array
    {
        $headers = array_merge(
            $options['headers'] ?? [],
            [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
            ],
        );

        return array_merge($options, [
            'headers' => $headers,
            'timeout' => $timeout,
        ]);
    }

    private function isRetryable(int $statusCode, int $attempt): bool
    {
        return $attempt < self::MAX_ATTEMPTS
            && in_array($statusCode, self::RETRYABLE_HTTP_CODES, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(string $content, int $statusCode, string $url): array
    {
        if ($statusCode >= 400) {
            $this->logger?->error('MiniMaxHttpClient: HTTP error', [
                'url'    => $url,
                'status' => $statusCode,
                'body'   => $this->safeTruncate($content),
            ]);
            throw new MiniMaxApiException(
                "MiniMax API returned HTTP {$statusCode}",
                $statusCode,
            );
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new MiniMaxApiException(
                'MiniMax API returned a non-JSON response',
                $statusCode,
            );
        }

        $baseResp = $decoded['base_resp'] ?? [];
        // $baseResp is either the original array<string, mixed> (when the
        // key exists) or an empty array (default from ??). Both are
        // already array-typed — no is_array() guard needed.
        $businessStatus = isset($baseResp['status_code']) ? (int) $baseResp['status_code'] : 0;

        if ($businessStatus !== 0) {
            $message = isset($baseResp['status_msg']) ? (string) $baseResp['status_msg'] : 'unknown';
            $this->logger?->error('MiniMaxHttpClient: business error', [
                'url'         => $url,
                'status_code' => $businessStatus,
                'status_msg'  => $message,
            ]);
            throw new MiniMaxApiException(
                "MiniMax API error ({$businessStatus}): {$message}",
                $businessStatus,
                $baseResp,
            );
        }

        return $decoded;
    }

    private function backoffMicroseconds(int $attempt): int
    {
        return match ($attempt) {
            1 => 250_000,
            2 => 750_000,
            default => 0,
        };
    }

    private function safeTruncate(string $content, int $maxChars = 500): string
    {
        return mb_strlen($content) > $maxChars
            ? mb_substr($content, 0, $maxChars) . '…[truncated]'
            : $content;
    }
}
