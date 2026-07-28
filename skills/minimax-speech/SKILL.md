---
name: minimax-speech
description: Synthesise speech from text via the MiniMax multimodal plugin (single `synthesize` operation). Use when the user asks to "speak", "say", "read aloud", "announce", "narrate", "voice-over", or needs an MP3 for a prompt / intro / alert / instructional line. Pick a `voice_id` from MiniMax's voice library (English / Chinese / Japanese / Korean / Spanish / etc.), override `speed` (0.5–2.0), and pass a `filename` for the Media Archive entry.
license: MIT
compatibility: spora>=0.7 spora-plugin-minimax>=1.0
metadata:
  author: spora-ai
  version: "1.0"
allowed-tools: Spora\Plugins\MiniMax\Tools\MiniMaxSpeechTool
---

# MiniMax speech

One tool, one `synthesize` operation. Wraps MiniMax's `t2a_v2` (text-to-audio) endpoint and returns a playable MP3 — served as an `<audio>` element from `/api/v1/assets/<token>.mp3` (the LocalAssetStore path is wired in `MiniMaxPlugin::register()` specifically for this tool, so the chat bubble never carries a `data:` URI).

## Calling

```
minimax_speech(text: "<script>", voice_id: "English_PassionateWarrior", speed: 1.0, filename: "intro-greeting")
```

| Parameter   | Required | Default                       | Notes |
|-------------|----------|-------------------------------|-------|
| `text`      | Yes      | —                             | The text to speak (max 10000 chars). No SSML — MiniMax's API takes plain text. |
| `voice_id`  | No       | `English_PassionateWarrior` (operator setting) | Override per call. Must be a valid MiniMax voice id — see the voice library section below. |
| `speed`     | No       | `1.0`                         | Multiplier in `[0.5, 2.0]`. Use `0.85–0.95` for deliberate narration; `1.1–1.3` for energetic / promotional reads. |
| `filename`  | No       | auto                          | Stem only — extension is auto-appended. Sanitised like the other tools. |

### Resolution order for the voice

```
LLM-supplied voice_id (per call)
   → operator's voice_id setting on the tool
   → hard-coded default (English_PassionateWarrior)
```

Pick a voice that matches the language of `text` — MiniMax's multilingual voices render native pronunciation / cadence; mixing languages usually degrades quality.

## Voice library

`voice_id` is a free-form string the MiniMax API accepts. The operator's reference list lives at **[MiniMax's voice library docs](https://platform.minimax.io/docs/api-reference/voice-management)** — it changes over time, so don't memorise it; do call out to a tool / the user when you need a specific voice that's not in the table below.

Common, well-supported voices (snapshot — always verify against the current library):

| Voice id                       | Language    | Character |
|--------------------------------|-------------|-----------|
| `English_PassionateWarrior`    | English     | Default — confident, energetic male. |
| `English_PassionatePrincess`   | English     | Bright, feminine counterpart to the default. |
| `English_Graceful_Lady`        | English     | Calm, mature female narrator. |
| `English_Soft_Girl`            | English     | Young female, soft delivery (good for children's content / gentle narration). |
| `English_Steady_Man`           | English     | Steady, neutral male (good for instructional reads). |
| `English_Trustworth_Man`       | English     | Authoritative male (corporate / corporate-training). |
| `English_Lively_Youth`         | English     | Young, energetic male. |
| `English_ReservedYoungMan`     | English     | Young male, measured tone. |
| `Chinese (Mandarin)_Warm_Girl` | Chinese     | Warm Mandarin female. |
| `Chinese (Mandarin)_Gentle_Man`| Chinese     | Calm Mandarin male. |
| `Chinese (Mandarin)_Steady_Man`| Chinese     | Neutral Mandarin male (audiobook-style). |
| `Japanese_Graceful_Lady`       | Japanese    | Calm Japanese female. |
| `Japanese_Lively_Youth`        | Japanese    | Bright Japanese male. |
| `Korean_…`                     | Korean      | Pick from the library. |
| `Spanish_…`, `French_…`, etc.  | various     | Pick from the library. |

The list above is non-exhaustive and may be stale. When the user asks for a specific voice not in this table, ask them — don't invent an id.

**Choosing a voice** — match to content:

| Content                                  | Suggested | Avoid |
|------------------------------------------|-----------|-------|
| Friendly product intro / SaaS promo      | `English_PassionateWarrior` or `English_Lively_Youth` | `English_ReservedYoungMan` (too quiet) |
| Calm audiobook narration                 | `English_Graceful_Lady` or `English_Steady_Man` | energetic voices |
| Children's story / bedtime               | `English_Soft_Girl` or `English_ReservedYoungMan` | aggressive voices |
| Corporate training / IVR                 | `English_Trustworth_Man` or `English_Steady_Man` | playful voices |
| News / documentary / report              | `English_Steady_Man` or `English_Trustworth_Man` | `-Lively` / `-Passionate` voices |
| Non-English text                         | Use a voice with that language prefix | Cross-language default |

## Settings (operator-scoped)

| Setting                   | Default                       | What it does |
|---------------------------|-------------------------------|--------------|
| `api_key`                 | — (required)                  | MiniMax API key. Required. Shared with image/music/video. |
| `base_url`                | `https://api.minimax.io`      | Override for China-region (`https://api.minimaxi.com`) or a private gateway. |
| `model`                   | `speech-2.8-hd`               | TTS model id. HD is higher fidelity but slower; switch to the Turbo variant on cost-sensitive agents. |
| `voice_id`                | `English_PassionateWarrior`   | Per-call `voice_id` overrides this. Set this to the operator's preferred house voice. |
| `http_timeout_seconds`    | `60`                          | Per-request timeout. Short utterances rarely exceed 10 s; raise if the operator sees cURL 28 errors. |

## Limits

- `text` max length: **10000 characters** (the tool rejects longer text).
- `speed` range: `[0.5, 2.0]`. `0.5` is slow, `2.0` is fast; out-of-range values cause the tool to fail validation.
- `filename` max length: **120 chars** (sanitised like the other tools).
- Output MP3: 32 kHz mono, 128 kbps, ~50 KB–500 KB for typical utterances. The chat bubble wraps the file in a standard `<audio>` element.

## Rendering

Echo the tool's `content` block verbatim — the `<audio>` element is already in place, served from `/api/v1/assets/<token>.mp3`:

```html
Synthesized speech (7.1s, 115 KB, 122 chars).

<audio controls preload="metadata" src="/api/v1/assets/<token>.mp3"></audio>

Voice: English_PassionateWarrior.

Use the same audio embed above to show the media player in your reply.
```

The "Use the same audio embed above…" sentence is the cue for future turns — don't strip it. If the user asks for the raw URL, read it from `ToolResult.data.asset_url`; never try to recover it from the markdown.

If the file is missing / fails to load (404, expired), the Audio Archive row will still be in the Media Archive detail page with a redownload button — point the user there.

## Failure modes

- `MiniMax API key is not configured for this agent.` — `api_key` setting is empty at every scope. Edit the tool's settings.
- `MiniMax returned no audio data.` — upstream returned empty `audio` and `audio_url` fields. Retry once; if it still fails, surface to the user.
- `MiniMax returned audio in an unsupported format.` — defensive guard, normally unreachable. Retry.
- `Text exceeds the 10000-character MiniMax limit.` — split the text into ≤ 10000-char chunks and call once per chunk; concatenate the audio (do not call multiple times in parallel — MiniMax serialises audio differently per generation, so merge by prompt rather than expecting cross-segment continuity).
- `Speed must be between 0.5 and 2.0.` — out-of-range `speed`. Re-call with a value in range.

## Don'ts

- **Don't invent a `voice_id`** that doesn't exist — MiniMax responds with an opaque 4xx error and you can't tell from the message whether you mistyped or the voice was retired.
- **Don't fabricate audio.** If the tool fails, say so. Don't pretend a generation succeeded.
- **Don't pass `text` longer than 10000 chars in one call.** The tool truncates / rejects; either trim or chunk.
- **Don't loop back to the speech tool** for "verification" — one call per utterance is the rule; extra calls cost quota and may drift prosody.
