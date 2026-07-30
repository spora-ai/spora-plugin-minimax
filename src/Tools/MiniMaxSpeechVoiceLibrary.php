<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax\Tools;

/**
 * Stateless helpers for the `minimax_speech` `voices` operation:
 * upstream response parsing, client-side filtering, and rendering.
 *
 * Extracted out of {@see MiniMaxSpeechTool} so the main tool stays
 * inside the SonarQube S1448 (≤20 methods) threshold. None of these
 * helpers touch plugin state — they're pure functions of the response
 * payload and the caller's arguments, which is why they live as
 * `static` methods rather than a constructor-injected service.
 */
final class MiniMaxSpeechVoiceLibrary
{
    /** Top-level bucket keys MiniMax can return from `POST /v1/get_voice`. */
    public const SUPPORTED_BUCKETS = ['system_voice', 'voice_cloning', 'voice_generation'];

    /** Top-level keys older MiniMax snapshots used before the bucket split. */
    public const LEGACY_KEYS = ['voice_list', 'voices'];

    public const DEFAULT_VOICE_LIMIT = 50;
    public const MAX_VOICE_LIMIT     = 500;

    /**
     * Resolve the caller's `voice_type` to the upstream bucket list we
     * should consult. `all` is a *plugin-side* merge of every bucket —
     * MiniMax accepts only `system`, `voice_cloning`, or `voice_generation`
     * upstream, so we always pull a real response and pick buckets
     * client-side.
     *
     * @return list<string>
     */
    public static function resolveBuckets(string $voiceType): array
    {
        return match ($voiceType) {
            'system'           => ['system_voice'],
            'voice_cloning'    => ['voice_cloning'],
            'voice_generation' => ['voice_generation'],
            default            => self::SUPPORTED_BUCKETS,
        };
    }

    /**
     * Per-operation `voice_type` enum: `system | voice_cloning |
     * voice_generation | all`, default `system`. Out-of-enum values
     * fall back to `system` (the runtime schema validator catches
     * this earlier in normal operation; this is defence-in-depth).
     *
     * @param array<string, mixed> $arguments
     */
    public static function resolveVoiceType(array $arguments): string
    {
        $raw = trim((string) ($arguments['voice_type'] ?? ''));
        if ($raw === '') {
            return 'system';
        }
        $allowed = ['system', 'voice_cloning', 'voice_generation', 'all'];
        return in_array($raw, $allowed, true) ? $raw : 'system';
    }

    /**
     * Per-operation `limit` clamp: `1..{@see self::MAX_VOICE_LIMIT}`,
     * default {@see self::DEFAULT_VOICE_LIMIT}. Non-numeric / missing
     * values fall back to the default.
     *
     * @param array<string, mixed> $arguments
     */
    public static function resolveLimit(array $arguments): int
    {
        $raw = $arguments['limit'] ?? null;
        if ($raw === null || !is_numeric($raw)) {
            return self::DEFAULT_VOICE_LIMIT;
        }
        return max(1, min(self::MAX_VOICE_LIMIT, (int) $raw));
    }

    /**
     * Walk the requested buckets in order and return the flattened
     * voice list tagged with the bucket name each entry came from
     * (`_source`). Each bucket's payload may be either a list of
     * voice entries (current shape) or a single voice object
     * (older shapes) — both are normalised here.
     *
     * Falls back to the legacy top-level `voice_list` / `voices`
     * keys only when the response has none of the new bucket
     * keys at all (older MiniMax snapshots).
     *
     * @param  array<string, mixed> $response
     * @return list<array<string, mixed>>
     */
    public static function extractVoices(array $response, string $voiceType = 'all'): array
    {
        $sources   = self::resolveBuckets($voiceType);
        $flat      = self::flattenBuckets($response, $sources);
        if ($flat !== []) {
            return $flat;
        }
        return self::extractLegacyVoices($response, $sources);
    }

    /**
     * Apply client-side filters over an already-flattened voice list.
     *
     * `voice_id` takes precedence: when it's non-empty it acts as an
     * exact match and `language` / `gender` are ignored. This matches
     * the documented SKILL.md behaviour ("Other filters are ignored
     * when this is set") — calling `voices(voice_id: "X")` is the
     * supported way to check whether `X` is available on this
     * account.
     *
     * @param  list<array<string, mixed>> $voices
     * @param  array<string, mixed>       $arguments
     * @return list<array<string, mixed>>
     */
    public static function applyClientFilters(array $voices, array $arguments): array
    {
        $voiceIdFilter = mb_strtolower(trim((string) ($arguments['voice_id'] ?? '')));
        $languageNeedle = mb_strtolower(trim((string) ($arguments['language'] ?? '')));
        $genderNeedle   = mb_strtolower(trim((string) ($arguments['gender'] ?? '')));

        // No filters → return everything.
        if ($voiceIdFilter === '' && $languageNeedle === '' && $genderNeedle === '') {
            return $voices;
        }

        $out = [];
        foreach ($voices as $v) {
            $voiceId = (string) ($v['voice_id'] ?? '');

            // `voice_id` is an exact match and short-circuits
            // `language` / `gender`. Stale auxiliary filters can't
            // hide an otherwise available voice.
            if ($voiceIdFilter !== '') {
                if (mb_strtolower($voiceId) !== $voiceIdFilter) {
                    continue;
                }
                $out[] = $v;
                continue;
            }

            $haystack = self::flattenVoiceText($v);
            if ($languageNeedle !== '' && mb_strpos($haystack, $languageNeedle) === false) {
                continue;
            }
            if ($genderNeedle !== '' && mb_strpos($haystack, $genderNeedle) === false) {
                continue;
            }
            $out[] = $v;
        }
        return $out;
    }

    /**
     * Flatten a voice entry into a single lower-case string spanning
     * `voice_name` and every `description[]` element. Used as the
     * haystack for the `language` / `gender` substring filters.
     *
     * @param array<string, mixed> $v
     */
    public static function flattenVoiceText(array $v): string
    {
        $bits = [];
        if (isset($v['voice_name']) && is_string($v['voice_name'])) {
            $bits[] = $v['voice_name'];
        }
        if (isset($v['description']) && is_array($v['description'])) {
            foreach ($v['description'] as $line) {
                if (is_string($line)) {
                    $bits[] = $line;
                }
            }
        }
        return mb_strtolower(implode(' ', $bits));
    }

    /**
     * Render the heading + Markdown bullet list the LLM will see in
     * the chat transcript. Each bullet quotes the `voice_id` in
     * backticks and appends `voice_name` + the first description line
     * so the language / gender cues land in the visible transcript.
     *
     * @param  list<array<string, mixed>> $voices
     * @param  array<string, mixed>       $arguments
     */
    public static function renderVoicesList(array $voices, array $arguments): string
    {
        $count   = count($voices);
        $filters = self::summariseFilters($arguments);
        $heading = $filters === ''
            ? "Available MiniMax voices ({$count}):"
            : "Available MiniMax voices ({$count} matching {$filters}):";

        $lines = [];
        foreach ($voices as $v) {
            $lines[] = '- ' . self::formatVoiceLine($v);
        }

        return $heading . "\n\n"
            . implode("\n", $lines) . "\n\n"
            . "Pick one whose language matches `text`, then call `minimax_speech(text: \"<text>\", voice_id: \"<voice_id>\")` "
            . '(omit `action` — `synthesize` is the default).';
    }

    /**
     * Render the "no voices" message. Three cases the LLM used to
     * conflate (see the docblock history in
     * {@see MiniMaxSpeechTool::listVoices()}): empty bucket, filter
     * excluded everything, or `voice_id` exact match failed. The
     * leading line of each message is distinct so the LLM (and a
     * human reading the chat transcript) can tell which case they
     * hit without parsing the body text.
     *
     * @param array<string, mixed>       $arguments
     * @param list<array<string, mixed>> $allVoices
     * @param list<array<string, mixed>> $filteredVoices
     */
    public static function renderEmpty(array $arguments, array $allVoices, array $filteredVoices = []): string
    {
        $voiceType = self::resolveVoiceType($arguments);
        $filters   = self::summariseFilters($arguments);

        // Case 0: exact-match `voice_id` was supplied but no voice
        // with that id exists in the requested bucket. Distinct from
        // `language` / `gender` filter misses — different fix path
        // (the operator wants to know "this id isn't on this account").
        $voiceId = trim((string) ($arguments['voice_id'] ?? ''));
        if ($voiceId !== '' && $allVoices !== [] && $filteredVoices === []) {
            return "No voices matched your filter.\n\n"
                . "voice_id=\"{$voiceId}\" did not match any voice in voice_type=\"{$voiceType}\" on this MiniMax account. "
                . "Run `minimax_speech(action: \"voices\")` to enumerate the available ids — the voice library changes over time.";
        }

        // Case 1: bucket empty on this account. `voice_cloning` and
        // `voice_generation` are user-populated buckets — empty is
        // the default state until the operator has cloned or
        // generated a voice. `system` being empty is much rarer (and
        // would indicate a stranger MiniMax account state).
        if (empty($allVoices)) {
            $bucketNote = match ($voiceType) {
                'voice_cloning'    => 'voice_cloning is a user-populated bucket — it stays empty until you have cloned a voice and used it in at least one synthesize call. Switch `voice_type` to `system` for MiniMax\'s built-in library.',
                'voice_generation' => 'voice_generation is a user-populated bucket — it stays empty until you have generated a voice via MiniMax\'s text-to-voice API. Switch `voice_type` to `system` for MiniMax\'s built-in library.',
                'all'              => 'No voices on this MiniMax account at all (system + voice_cloning + voice_generation all empty). Confirm the `api_key` setting points at an account with voice access.',
                default            => 'MiniMax returned no `system_voice` entries for this account. Confirm the `api_key` setting points to a paid MiniMax plan that includes system voices.',
            };

            return "No voices available.\n\n"
                . "voice_type=\"{$voiceType}\" returned an empty bucket.\n\n"
                . $bucketNote;
        }

        // Case 2: bucket had voices, filter excluded all of them.
        $filterNote = $filters === ''
            ? 'No filter was supplied, so the bucket content is unexpected here — check the `voice_type` value.'
            : 'Drop the filter (or broaden it) and call `minimax_speech(action: "voices")` again. Filters are case-insensitive substring matches against `voice_name` + `description[]` — try a shorter needle (e.g. "ger" instead of "german").';

        return "No voices matched your filter.\n\n"
            . "MiniMax returned " . count($allVoices) . " voice(s) for voice_type=\"{$voiceType}\"; none matched {$filters}.\n\n"
            . $filterNote;
    }

    /**
     * Build the filter summary used in headings ("X matching language
     * contains \"german\"") and in the empty-results body. Empty
     * `voice_type: "system"` is omitted — `system` is the default and
     * spelling it out adds noise.
     *
     * @param  array<string, mixed> $arguments
     */
    public static function summariseFilters(array $arguments): string
    {
        $bits = [];
        $voiceType = trim((string) ($arguments['voice_type'] ?? ''));
        if ($voiceType !== '' && $voiceType !== 'system') {
            $bits[] = 'voice_type="' . $voiceType . '"';
        }
        $language = trim((string) ($arguments['language'] ?? ''));
        if ($language !== '') {
            $bits[] = 'language contains "' . $language . '"';
        }
        $gender = trim((string) ($arguments['gender'] ?? ''));
        if ($gender !== '') {
            $bits[] = 'description contains "' . $gender . '"';
        }
        $voiceId = trim((string) ($arguments['voice_id'] ?? ''));
        if ($voiceId !== '') {
            $bits[] = 'voice_id="' . $voiceId . '"';
        }
        return implode(', ', $bits);
    }

    /**
     * Format a single voice entry as a one-line Markdown bullet. The
     * `voice_id` is always backtick-quoted so the LLM can copy it
     * verbatim. `voice_name` and the first description line follow in
     * plain text — they're the language / gender cues the LLM needs.
     *
     * @param array<string, mixed> $v
     */
    public static function formatVoiceLine(array $v): string
    {
        $id   = (string) ($v['voice_id'] ?? '');
        $bits = [$id !== '' ? "`{$id}`" : '(missing voice_id)'];

        $description = $v['description'] ?? null;
        $firstLine   = is_array($description) ? (string) ($description[0] ?? '') : '';
        $voiceName   = isset($v['voice_name']) && is_string($v['voice_name']) ? $v['voice_name'] : '';

        $meta = [];
        if ($voiceName !== '') {
            $meta[] = $voiceName;
        }
        if ($firstLine !== '') {
            $meta[] = $firstLine;
        }
        if ($meta !== []) {
            $bits[] = '— ' . implode(' — ', $meta);
        }
        return implode(' ', $bits);
    }

    /**
     * @param array<string, mixed> $response
     * @param list<string>         $sources
     * @return list<array<string, mixed>>
     */
    private static function flattenBuckets(array $response, array $sources): array
    {
        $out = [];
        foreach ($sources as $source) {
            $bucket = $response[$source] ?? null;
            if (!is_array($bucket)) {
                continue;
            }
            $entries = array_is_list($bucket) ? $bucket : [$bucket];
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $entry['_source'] = $source;
                $out[] = $entry;
            }
        }
        return $out;
    }

    /**
     * Consult the older MiniMax response shape (`voice_list` /
     * `voices` at the top level). The early-bail distinguishes
     * "new-shape bucket empty" (don't fall through to legacy) from
     * "old-shape response with no new keys" (try the legacy keys).
     *
     * @param  array<string, mixed> $response
     * @param  list<string>         $sources
     * @return list<array<string, mixed>>
     */
    private static function extractLegacyVoices(array $response, array $sources): array
    {
        foreach ($sources as $key) {
            if (array_key_exists($key, $response)) {
                return [];
            }
        }
        foreach (self::LEGACY_KEYS as $key) {
            $fallback = $response[$key] ?? null;
            if (is_array($fallback) && array_is_list($fallback)) {
                return $fallback;
            }
        }
        return [];
    }
}
