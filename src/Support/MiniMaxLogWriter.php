<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax\Support;

use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Persists a row to `minimax_generation_log` per tool call. Best-effort: any
 * DB error is logged and swallowed so a logging failure can never fail the
 * actual tool call.
 *
 * The redactor strips secrets and oversized blobs before insert so the audit
 * trail can be safely inspected without leaking credentials or flooding the
 * table with image/audio bytes.
 */
final class MiniMaxLogWriter
{
    private const MAX_BLOB_BYTES = 1024;          // base64 / inline data > 1 KB
    private const MAX_PAYLOAD_BYTES = 4096;      // JSON column size cap

    /** Substrings that mark a key as containing a credential. Case-insensitive. */
    private const SENSITIVE_KEY_FRAGMENTS = [
        'api_key',
        'apikey',
        'authorization',
        'bearer',
        'password',
        'secret',
        'token',
    ];

    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * @param array<string, mixed> $request  Tool-call argument payload (post-redaction).
     * @param array<string, mixed> $response Decoded API response (post-redaction), or empty on error.
     */
    public function record(
        string $provider,
        string $qualifiedToolName,
        array $request,
        array $response,
        bool $success,
        ?string $error = null,
        ?int $userId = null,
        ?int $agentId = null,
    ): void {
        try {
            Capsule::table('minimax_generation_log')->insert([
                'user_id'          => $userId,
                'agent_id'         => $agentId,
                'tool_name'        => $qualifiedToolName,
                'provider'         => $provider,
                'request_payload'  => $this->encode($this->redact($request)),
                'response_payload' => $response === [] ? null : $this->encode($this->redact($response)),
                'status'           => $success ? 'ok' : 'error',
                'error'            => $error !== null ? mb_substr($error, 0, 2000) : null,
                'created_at'       => date('Y-m-d H:i:s'),
                'updated_at'       => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            $this->logger?->warning('MiniMaxLogWriter: failed to persist log row', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Recursively replace values for sensitive keys with '***', and replace
     * oversized base64-like strings with a size marker.
     *
     * @param  array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function redact(array $payload): array
    {
        $result = [];
        foreach ($payload as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $result[$key] = '***';
                continue;
            }
            if (is_string($value) && $this->looksLikeBase64Blob($value)) {
                $result[$key] = '[base64 ' . strlen($value) . ' bytes]';
                continue;
            }
            if (is_array($value)) {
                $result[$key] = $this->redact($value);
                continue;
            }
            $result[$key] = $value;
        }
        return $result;
    }

    private function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($lower, $fragment)) {
                return true;
            }
        }
        return false;
    }

    private function looksLikeBase64Blob(string $value): bool
    {
        if (strlen($value) <= self::MAX_BLOB_BYTES) {
            return false;
        }
        // Heuristic: long strings of base64-alphabet characters are likely
        // inline image / audio data that shouldn't be persisted verbatim.
        return (bool) preg_match('/^[A-Za-z0-9+\/=\r\n]{' . self::MAX_BLOB_BYTES . ',}$/', $value);
    }

    /**
     * @param  array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return '{"_encoding_error":true}';
        }
        if (strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
            $trimmed = mb_substr($encoded, 0, self::MAX_PAYLOAD_BYTES);
            return $trimmed . '…[truncated]';
        }
        return $encoded;
    }
}
