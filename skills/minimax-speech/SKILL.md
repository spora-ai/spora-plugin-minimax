---
name: minimax-speech
description: "Synthesise speech from text via the MiniMax t2a_v2 API, or list the upstream voice library so the LLM can pick a language-matched `voice_id` before issuing a synthesis call. **Two operations**: `synthesize` (default; text → MP3) and `voices` (list available voice ids, filterable by `voice_type` / `language` / `gender` / `voice_id` / `limit`; **none of those filter fields are required** — a no-arg call returns the full built-in library). **Workflow rule**: when synthesising non-default-language speech, **always call `voices` first** to discover a voice whose language matches `text`, then call `synthesize` with the chosen `voice_id` — never guess from the snapshot table at the bottom of this skill. Use when the user asks to 'speak', 'say', 'read aloud', 'announce', 'narrate', 'voice-over', 'list voices', 'which voices do I have', or needs an MP3 for a prompt / intro / alert / instructional line."
license: MIT
compatibility: spora>=0.7 spora-plugin-minimax>=1.0
metadata:
  author: spora-ai
  version: "1.0"
allowed-tools: Spora\Plugins\MiniMax\Tools\MiniMaxSpeechTool
---

# MiniMax speech

**Multi-operation tool**, selected with the `action` discriminator. Defaults to `synthesize` for backward compatibility with pre-multi-op callers.

| When the user wants…                                    | Operation    | Required discriminator |
|--------------------------------------------------------|--------------|-----------------------|
| A finished MP3 from text                                | `synthesize` | (default — omit `action`) |
| The list of MiniMax voice ids (filtered or full)        | `voices`     | `action: "voices"`    |

`api_key` is required for both operations (both hit the MiniMax HTTP API). The synth path returns a playable MP3 served from `/api/v1/assets/<token>.mp3` — the LocalAssetStore path is wired in `MiniMaxPlugin::register()` specifically for this tool, so the chat bubble never carries a `data:` URI.

## Workflow rule — discover first, then synthesise

For any non-English (or non-default-language) text, **call `voices` before `synthesize`**. The MiniMax voice library changes over time; the snapshot table at the bottom of this skill ages. Use `voices` to find a `voice_id` whose `description` matches the target language, then pass that id to `synthesize`.

```
1. minimax_speech(action: "voices", language: "German", gender: "male")
   → pick a voice_id whose description mentions German + male
2. minimax_speech(text: "<text>", voice_id: "<chosen voice_id>")
   → omit `action`; default is `synthesize`
```

For English text in the default voice, skip step 1 — the operator-configured `voice_id` setting (`English_PassionateWarrior`) is the default and works without a lookup.

**Do not invent a `voice_id`** from the snapshot table when `voices` is reachable. MiniMax's `synthesize` returns an opaque `voice id not exist` (error 2054) for retired or mis-typed ids and there is no way to tell the difference. `voices` is the only safe discovery path.

## Operations at a glance

| Op          | Endpoint                                                | Backed by |
|-------------|----------------------------------------------------------|-----------|
| `synthesize` | `POST /v1/t2a_v2`                                       | `MiniMaxHttpClient::postJson()` |
| `voices`    | `POST /v1/get_voice` ([reference](https://platform.minimax.io/docs/api-reference/voice-management-get)) — body `{"voice_type": "<bucket>"}` | `MiniMaxHttpClient::postJson()` |

**Note**: `voices` is a `POST`, not a `GET`. MiniMax's `/v1/get_voice` endpoint accepts exactly one body field — `voice_type` — and returns up to three buckets (`system_voice[]`, `voice_cloning[]`, `voice_generation[]`). The `language` / `gender` / `voice_id` / `limit` filters you can pass to `minimax_speech(action: "voices")` are applied **client-side** over `voice_name` + flattened `description[]`, because MiniMax's upstream API does not expose server-side filters for those fields.

## Calling

### `synthesize` (default)

```
minimax_speech(text: "<script>", voice_id: "English_PassionateWarrior", speed: 1.0, filename: "intro-greeting")
```

Or with the discriminator explicit (same call):

```
minimax_speech(action: "synthesize", text: "<script>", voice_id: "English_PassionateWarrior", speed: 1.0)
```

| Parameter   | Required when…                 | Default                       | Notes |
|-------------|--------------------------------|-------------------------------|-------|
| `action`    | always                         | `synthesize` (default)        | The discriminator. The runtime schema validator enforces the enum. |
| `text`      | `action == "synthesize"`       | —                             | The text to speak (max 10000 chars). No SSML — MiniMax's API takes plain text. |
| `voice_id`  | never                          | `English_PassionateWarrior` (operator setting) | Override per call. Must be a valid MiniMax voice id — see **Voice library** below and the `voices` operation. |
| `speed`     | never                          | `1.0`                         | Multiplier in `[0.5, 2.0]`. Use `0.85–0.95` for deliberate narration; `1.1–1.3` for energetic / promotional reads. |
| `filename`  | never                          | auto                          | Stem only — extension is auto-appended. Sanitised like the other tools. |

### `voices`

```
minimax_speech(action: "voices")                                              # full built-in library
minimax_speech(action: "voices", language: "German", gender: "male")          # client-side filter
minimax_speech(action: "voices", voice_type: "all", limit: 25)                # across every bucket, capped
minimax_speech(action: "voices", voice_id: "German_FriendlyMan")              # exact-match check
```

| Parameter   | Required when…          | Default  | Notes |
|-------------|------------------------|----------|-------|
| `voice_type`| never                  | `system` | Upstream bucket. Enum: `system` (built-in voices, the default), `voice_cloning` (user-cloned voices; only present after first synthesis), `voice_generation` (voices generated via the text-to-voice API), `all` (all three buckets merged). |
| `voice_id`  | never                  | —        | Exact-match filter against `voice_id`. Other filters are ignored when this is set. |
| `language`  | never                  | —        | Client-side case-insensitive substring match against `voice_name` + flattened `description[]`. Common needles: `"English"`, `"Chinese"`, `"Japanese"`, `"Korean"`, `"Spanish"`, `"French"`, `"German"`, `"Italian"`, `"Portuguese"`. |
| `gender`    | never                  | —        | Client-side case-insensitive substring match against `description[]`. MiniMax tags gender inside the free-text description (e.g. `"...male executive voice..."`), not as a separate field. Common needles: `"male"`, `"female"`. |
| `limit`     | never                  | `50`     | Client-side cap on the rendered bullet count. Hard-capped at **500** to keep the response bounded. |

**None of the `voices` parameters are required.** A bare call (`minimax_speech(action: "voices")`) returns the full built-in library so the LLM can iterate. The filter parameters exist purely to narrow that list client-side.

### Per-operation parameter requirements

The array-form `required: ['op_name']` on `#[ToolParameter]` makes a parameter required only when the dispatcher routes to that operation:

```
                | synthesize | voices
----------------|------------|--------
text           | required   | (skipped)
speed          | optional   | (skipped)
filename       | optional   | (skipped)
voice_id       | optional   | optional   (override vs exact-match)
voice_type     | (skipped)  | optional   (default: "system")
language       | (skipped)  | optional
gender         | (skipped)  | optional
limit          | (skipped)  | optional
```

> `text` is the only parameter that's truly required, and **only** for `synthesize`. Calling `synthesize` without `text` produces a clear validation error; calling `voices` with no filters returns the full library.

## Resolution order for `voice_id` (synthesize)

```
LLM-supplied voice_id (per call)
   → operator's voice_id setting on the tool
   → hard-coded default (English_PassionateWarrior)
```

Pick a voice that matches the language of `text` — MiniMax's multilingual voices render native pronunciation / cadence; mixing languages usually degrades quality. **Do not guess from the snapshot below** — call `voices` first.

## Voice library

`voice_id` is a free-form string the MiniMax API accepts. The authoritative reference list lives at **[MiniMax's voice library docs](https://platform.minimax.io/docs/api-reference/voice-management)** — it changes over time, so do **not** memorise it; do call `voices` when you need a specific voice that's not in the table below.

Common, well-supported voices (snapshot — always verify against the current library or call `voices(action: "voices", language: "<lang>")`):

| Voice id                          | Language | Character |
|-----------------------------------|----------|-----------|
| `English_PassionateWarrior`       | English  | Default — confident, energetic male. |
| `English_PassionatePrincess`      | English  | Bright, feminine counterpart to the default. |
| `English_Graceful_Lady`           | English  | Calm, mature female narrator. |
| `English_Soft_Girl`               | English  | Young female, soft delivery (good for children's content / gentle narration). |
| `English_Steady_Man`              | English  | Steady, neutral male (good for instructional reads). |
| `English_Trustworth_Man`          | English  | Authoritative male (corporate / corporate-training). |
| `English_Lively_Youth`            | English  | Young, energetic male. |
| `English_ReservedYoungMan`        | English  | Young male, measured tone. |
| `Chinese (Mandarin)_Warm_Girl`    | Chinese  | Warm Mandarin female. |
| `Chinese (Mandarin)_Gentle_Man`   | Chinese  | Calm Mandarin male. |
| `Chinese (Mandarin)_Steady_Man`   | Chinese  | Neutral Mandarin male (audiobook-style). |
| `Japanese_Graceful_Lady`          | Japanese | Calm Japanese female. |
| `Japanese_Lively_Youth`           | Japanese | Bright Japanese male. |
| `German_FriendlyMan`              | German   | Friendly, middle-aged male narrator. |
| `Italian_Narrator`                | Italian  | Steady, mature male narrator. |
| `Korean_…`                        | Korean   | Pick from the library. |
| `Spanish_…`, `French_…`, etc.     | various  | Pick from the library. |

The list above is non-exhaustive and may be stale. **Prefer `voices` over the snapshot** when the user asks for a specific voice not in this table.

**Choosing a voice** — match to content (these rules of thumb still hold even when the LLM looks up via `voices` instead of guessing):

| Content                                  | Suggested | Avoid |
|------------------------------------------|-----------|-------|
| Friendly product intro / SaaS promo      | `English_PassionateWarrior` or `English_Lively_Youth` | `English_ReservedYoungMan` (too quiet) |
| Calm audiobook narration                 | `English_Graceful_Lady` or `English_Steady_Man` | energetic voices |
| Children's story / bedtime               | `English_Soft_Girl` or `English_ReservedYoungMan` | aggressive voices |
| Corporate training / IVR                 | `English_Trustworth_Man` or `English_Steady_Man` | playful voices |
| News / documentary / report              | `English_Steady_Man` or `English_Trustworth_Man` | `-Lively` / `-Passionate` voices |
| Non-English text                         | `voices(language: "<lang>")` first, then pick a `voice_id` whose description matches the character you want | Cross-language default |

## Settings (operator-scoped)

| Setting                   | Default                       | What it does |
|---------------------------|-------------------------------|--------------|
| `api_key`                 | — (required)                  | MiniMax API key. Required. Shared with image/music/video. **Both `synthesize` and `voices` need it.** |
| `base_url`                | `https://api.minimax.io`      | Override for China-region (`https://api.minimaxi.com`) or a private gateway. |
| `model`                   | `speech-2.8-hd`               | TTS model id (used by `synthesize` only). HD is higher fidelity but slower; switch to the Turbo variant on cost-sensitive agents. |
| `voice_id`                | `English_PassionateWarrior`   | Per-call `voice_id` overrides this (used by `synthesize` only). Set this to the operator's preferred house voice — but the LLM is free to override per call. |
| `http_timeout_seconds`    | `60`                          | Per-request timeout for both operations; voice lookup is fast (<5 s) but raise to 90 s if MiniMax is queueing. |

## Limits

- `text` max length: **10000 characters** (the tool rejects longer text). Synthesize-only.
- `speed` range: `[0.5, 2.0]`. Out-of-range values cause the tool to fail validation. Synthesize-only.
- `filename` max length: **120 chars** (sanitised like the other tools). Synthesize-only.
- `limit` (voices): `[1, 500]`, default `50`. The hard cap keeps the response sized for one chat bubble.
- `voice_type` (voices): enum `system | voice_cloning | voice_generation | all`; out-of-enum values fall back to `system` (defence-in-depth).
- Output MP3 (synthesize): 32 kHz mono, 128 kbps, ~50 KB–500 KB for typical utterances.

## Rendering

### `synthesize`

The tool returns a `<audio>` element via `MediaEmbed::audioFromUrl()`. Echo `content` verbatim — the `<audio>` element is already in place:

```html
Synthesized speech (7.1s, 115 KB, 122 chars).

<audio controls preload="metadata" src="/api/v1/assets/<token>.mp3"></audio>

Voice: English_PassionateWarrior.

Use the same audio embed above to show the media player in your reply.
```

If the file is missing / 404s, the Audio Archive row is in the Media Archive detail page with a redownload button — point the user there.

### `voices`

Echo `content` verbatim — the Markdown bullet list is meant for the LLM to grep:

```markdown
Available MiniMax voices (3 matching language contains "en"):

- `English_PassionateWarrior` — Passionate Warrior — A confident, energetic male voice in standard English.
- `English_Graceful_Lady` — Graceful Lady — A calm, mature female narrator in standard English.
- `English_Lively_Youth` — Lively Youth — A bright, energetic male voice in standard English.

Pick one whose language matches `text`, then call `minimax_speech(text: "<text>", voice_id: "<voice_id>")` (omit `action` — `synthesize` is the default).
```

Each bullet has the `voice_id` (backtick-quoted, copy-paste ready), then the `voice_name`, then the first `description` line. The description is what carries the language / gender / character cues — read it carefully.

For an empty result, the tool returns:

```markdown
No voices matched.

MiniMax returned 47 voice(s) for voice_type="system"; none matched the supplied filters.

Drop the filter (or broaden it) and call `minimax_speech(action: "voices")` again.
```

That's a `success: true` result — narrow the filter, don't treat it as an error. Structured `ToolResult.data` carries `count`, `voices[]`, `voice_type`, `total` (full library size), and `after_filter` (matched count before `limit` cap) for callers that prefer that over parsing markdown.

## Failure modes

- `MiniMax API key is not configured for this agent.` — `api_key` setting is empty at every scope. Edit the tool's settings. Affects both operations.
- `MiniMax returned no audio data.` (synthesize) — upstream returned empty `audio` and `audio_url` fields. Retry once; if it still fails, surface to the user.
- `MiniMax returned audio in an unsupported format.` (synthesize) — defensive guard, normally unreachable. Retry.
- `Text exceeds the 10000-character MiniMax limit.` (synthesize) — split the text into ≤ 10000-char chunks and call once per chunk; concatenate the audio (do not call multiple times in parallel — MiniMax serialises audio differently per generation, so merge by prompt rather than expecting cross-segment continuity).
- `Speed must be between 0.5 and 2.0.` (synthesize) — out-of-range `speed`. Re-call with a value in range.
- `voice_type must be one of: system, voice_cloning, voice_generation, all.` (voices) — defence-in-depth; the runtime schema validator catches this earlier.
- `gender must be "male" or "female".` (voices) — defence-in-depth; the runtime schema validator catches this earlier.
- `limit must be between 1 and N.` (voices) — clamped at 500; reduce the limit.
- `MiniMax API error (2054): voice id not exist.` (synthesize) — the `voice_id` you passed is retired or mis-typed. **Call `voices`** to find a current id; do not retry the same id.

## Don'ts

- **Don't invent a `voice_id`** that doesn't exist — MiniMax responds with an opaque 4xx error and you can't tell from the message whether you mistyped or the voice was retired. **Call `voices`** first.
- **Don't skip `voices` for non-English text.** The default `English_PassionateWarrior` will render Italian / German with English pronunciation. Use `voices(language: "<lang>")` to pick a native voice.
- **Don't fabricate audio.** If `synthesize` fails, say so. Don't pretend a generation succeeded.
- **Don't call `voices` in parallel with `synthesize`.** They're sequential — discover first, then synthesise.
- **Don't pass `text` longer than 10000 chars in one call.** The tool truncates / rejects; either trim or chunk.
- **Don't loop back to the speech tool** for "verification" — one call per utterance is the rule; extra calls cost quota and may drift prosody.
- **Don't read the cached voice snapshot in this skill as authoritative** — it ages. Use `voices` for anything you can't recognise.
