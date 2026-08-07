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
 * - Single-shot: every failure surfaces to the caller so the LLM can adapt on retry.
 *   Earlier versions retried 2x on 429/5xx with backoff; that wasted quota on
 *   expensive media-generation calls (image, video, music, speech) where the
 *   LLM can lower quality/size/duration instead of re-charging the same payload.
 * - Raises MiniMaxApiException on:
 *     - transport failure, or
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

        $this->logger?->debug('MiniMaxHttpClient: request', [
            'method'  => $method,
            'url'     => $url,
            'timeout' => $timeout,
        ]);

        try {
            $response = $this->httpClient->request($method, $url, $requestOptions);
            return $this->decodeResponse($response->getContent(false), $response->getStatusCode(), $url);
        } catch (TransportExceptionInterface $e) {
            $this->logger?->error('MiniMaxHttpClient: transport error', [
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
            $upstream = $this->extractUpstreamError($content);
            $message  = $upstream !== null
                ? "MiniMax API returned HTTP {$statusCode}: {$upstream}"
                : "MiniMax API returned HTTP {$statusCode}";
            throw new MiniMaxApiException(
                $message,
                $statusCode,
                $this->safeJsonDecode($content),
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

    /**
     * Extract the upstream `error.message` (with `error.code` prefix when
     * present) from a 4xx/5xx response body. Returns `null` when the body
     * isn't JSON, doesn't carry an `error` object, or the message is empty.
     *
     * Two shapes are accepted:
     *   - Anthropic-style: `{"type":"error","error":{"type":"...","message":"...","code":...}}`
     *   - MiniMax-style: `{"base_resp":{"status_msg":"...","status_code":...}}`
     * The Anthropic-style is what the v2 video endpoints use (the
     * "TokenPlan or Credit does not currently support MiniMax-H3 series
     * models (2013)" message that landed in `spora-local`); the
     * MiniMax-style is what the v1 endpoints use. Without this the LLM
     * only sees `MiniMax API returned HTTP 400` and has to guess.
     */
    private function extractUpstreamError(string $content): ?string
    {
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return null;
        }

        $anthropic = $this->anthropicErrorMessage($decoded);
        if ($anthropic !== null) {
            return $anthropic;
        }

        return $this->minimaxBaseRespErrorMessage($decoded);
    }

    /**
     * Extract an Anthropic-style error.message from a decoded body.
     * Returns `null` when the body doesn't carry an `error` object.
     *
     * @param array<string, mixed> $decoded
     */
    private function anthropicErrorMessage(array $decoded): ?string
    {
        $error = $decoded['error'] ?? null;
        if (!is_array($error)) {
            return null;
        }

        $message = $error['message'] ?? null;
        if (!is_string($message) || $message === '') {
            return null;
        }

        $code = $error['code'] ?? null;
        $prefix = (is_int($code) || (is_string($code) && $code !== ''))
            ? "[{$code}] "
            : '';
        return $prefix . $message;
    }

    /**
     * Extract a MiniMax-style base_resp.status_msg from a decoded body.
     * Returns `null` when the body doesn't carry a `base_resp` object.
     *
     * @param array<string, mixed> $decoded
     */
    private function minimaxBaseRespErrorMessage(array $decoded): ?string
    {
        $baseResp = $decoded['base_resp'] ?? null;
        if (!is_array($baseResp)) {
            return null;
        }

        $msg = $baseResp['status_msg'] ?? null;
        if (!is_string($msg) || $msg === '') {
            return null;
        }

        $code = $baseResp['status_code'] ?? null;
        $prefix = is_int($code) ? "[{$code}] " : '';
        return $prefix . $msg;
    }

    /**
     * Best-effort JSON decode for the exception context map. Returns an
     * empty array on failure (callers treat the context as advisory).
     *
     * @return array<string, mixed>
     */
    private function safeJsonDecode(string $content): array
    {
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function safeTruncate(string $content, int $maxChars = 500): string
    {
        return mb_strlen($content) > $maxChars
            ? mb_substr($content, 0, $maxChars) . '…[truncated]'
            : $content;
    }
}
