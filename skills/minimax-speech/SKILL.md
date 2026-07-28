---
name: minimax-speech
description: "Synthesise speech from text via the MiniMax t2a_v2 API, or fetch the upstream voice library (`GET /v1/get_voice`) so the LLM can pick a language-matched `voice_id` before issuing a synthesis call. **Two operations**: `synthesize` (default; text → MP3) and `voices` (list available voice ids, filterable by `language` / `gender` / `voice_id` / `limit`). Use when the user asks to 'speak', 'say', 'read aloud', 'announce', 'narrate', 'voice-over', 'list voices', 'which voices do I have', or needs an MP3 for a prompt / intro / alert / instructional line. When the `text` is in a specific language, prefer to look up the matching voice via `voices` rather than guessing from a hard-coded snapshot."
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

## Operations at a glance

| Op          | Endpoint                                                | Backed by |
|-------------|----------------------------------------------------------|-----------|
| `synthesize` | `POST /v1/t2a_v2`                                       | `MiniMaxHttpClient::postJson()` |
| `voices`    | `GET /v1/get_voice` ([reference](https://platform.minimax.io/docs/api-reference/voice-management-get)) | `MiniMaxHttpClient::getJson()` |

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
| `action`    | always                         | `synthesize` (default)        | Excluded from the parameters table for multi-op clarity; the discriminator. The runtime schema validator enforces the enum. |
| `text`      | `action == "synthesize"`       | —                             | The text to speak (max 10000 chars). No SSML — MiniMax's API takes plain text. |
| `voice_id`  | never                          | `English_PassionateWarrior` (operator setting) | Override per call. Must be a valid MiniMax voice id — see **Voice library** below. |
| `speed`     | never                          | `1.0`                         | Multiplier in `[0.5, 2.0]`. Use `0.85–0.95` for deliberate narration; `1.1–1.3` for energetic / promotional reads. |
| `filename`  | never                          | auto                          | Stem only — extension is auto-appended. Sanitised like the other tools. |

### `voices`

```
minimax_speech(action: "voices", language: "Japanese", gender: "female")
minimax_speech(action: "voices", voice_id: "English_Graceful_Lady")   # detail view for one voice
minimax_speech(action: "voices", limit: 25)                            # cap the response
```

| Parameter   | Required when…                | Default  | Notes |
|-------------|-------------------------------|----------|-------|
| `voice_id`  | never                         | —        | Fetch only this single voice id (detail view). When set, the other filters are ignored. |
| `language`  | never                         | —        | Filter by language (e.g. `"English"`, `"Chinese"`, `"Japanese"`, `"Korean"`, `"Spanish"`). MiniMax accepts any string and returns whatever matches. |
| `gender`    | never                         | —        | Enumerated: `"male"` or `"female"`. |
| `limit`     | never                         | `50`     | Max voices to return. Hard-capped at **500** to keep the response bounded — MiniMax has hundreds of voices and the message bubble can't render that. |

The tool returns a Markdown bullet list. Each bullet is:

```
- `<voice_id>` — language, gender, name
```

…with whichever fields MiniMax supplied (the upstream isn't always consistent across languages, and only `voice_id` is required to issue a follow-up `synthesize` call).

`voice_id` works for both `synthesize` (per-call override) and `voices` (filter / detail) — its semantics flip based on `action`.

### Per-operation parameter requirements

The array-form `required: ['op_name']` on `#[ToolParameter]` makes a parameter required only when the dispatcher routes to that operation:

```
                | synthesize | voices
----------------|------------|--------
text           | required   | (skipped)
speed          | optional   | (skipped)
filename       | optional   | (skipped)
voice_id       | optional   | optional   (semantics flip per op)
language       | (skipped)  | optional
gender         | (skipped)  | optional
limit          | (skipped)  | optional
```

> `voice_id` is the only parameter shared by both operations. The runtime schema marks it optional in both. Use `voices` first to **discover** a valid `voice_id` for the user's language, then `synthesize` with that id.

> `text` is the only parameter that's truly required, and **only** for `synthesize`. Calling `synthesize` without `text` produces a clear validation error; calling `voices` without filters returns the full library.

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
| Non-English text                         | Filter `voices` by `language` first, then pick the character that matches | Cross-language default |

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
Available MiniMax voices (3 matching language="en"):

- `English_PassionateWarrior` — en, male
- `English_Graceful_Lady` — en, female
- `English_Lively_Youth` — en, male

To use one: `minimax_speech(text: "<text>", voice_id: "<voice_id>")` or omit `action` (default is `synthesize`).
```

The "To use one" line is the handoff to `synthesize`. Don't strip it.

For an empty result, the tool returns:

```markdown
No voices matched the supplied filters.

Use a broader filter (or drop it entirely) and call `minimax_speech(action: "voices")` again.
```

That's a `success: true` result — narrow the filter, don't treat it as an error. Structured `ToolResult.data.count` and `ToolResult.data.voices` carry the same payload as JSON for callers that prefer that over parsing markdown.

## Failure modes

- `MiniMax API key is not configured for this agent.` — `api_key` setting is empty at every scope. Edit the tool's settings. Affects both operations.
- `MiniMax returned no audio data.` (synthesize) — upstream returned empty `audio` and `audio_url` fields. Retry once; if it still fails, surface to the user.
- `MiniMax returned audio in an unsupported format.` (synthesize) — defensive guard, normally unreachable. Retry.
- `Text exceeds the 10000-character MiniMax limit.` (synthesize) — split the text into ≤ 10000-char chunks and call once per chunk; concatenate the audio (do not call multiple times in parallel — MiniMax serialises audio differently per generation, so merge by prompt rather than expecting cross-segment continuity).
- `Speed must be between 0.5 and 2.0.` (synthesize) — out-of-range `speed`. Re-call with a value in range.
- `gender must be "male" or "female".` (voices) — defence-in-depth; the runtime schema validator catches this earlier.
- `limit must be between 1 and N.` (voices) — clamped at 500; reduce the limit.

## Don'ts

- **Don't invent a `voice_id`** that doesn't exist — MiniMax responds with an opaque 4xx error and you can't tell from the message whether you mistyped or the voice was retired. **Call `voices`** first.
- **Don't fabricate audio.** If `synthesize` fails, say so. Don't pretend a generation succeeded.
- **Don't call `voices` in parallel with `synthesize`.** They're sequential — discover first, then synthesise.
- **Don't pass `text` longer than 10000 chars in one call.** The tool truncates / rejects; either trim or chunk.
- **Don't loop back to the speech tool** for "verification" — one call per utterance is the rule; extra calls cost quota and may drift prosody.
- **Don't read the cached voice snapshot in this skill as authoritative** — it ages. Use `voices` for anything you can't recognise.
