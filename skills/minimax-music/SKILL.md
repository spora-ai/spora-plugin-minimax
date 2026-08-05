---
name: minimax-music
description: "Generate music (instrumental or with lyrics) or write / edit song lyrics via the MiniMax multimodal plugin. **Three operations**: `compose` (instrumental or vocal song from a style prompt + optional lyrics), `write_lyrics` (full lyrics from a topic / style), `edit_lyrics` (rewrite existing lyrics per an instruction). Use when the user asks for a 'song', 'music', 'soundtrack', 'jingle', 'background music', 'lyrics', or wants lyrics written / re-written from a topic."
license: MIT
compatibility: spora>=0.7 spora-plugin-minimax>=1.0
metadata:
  author: spora-ai
  version: "1.0"
allowed-tools: Spora\Plugins\MiniMax\Tools\MiniMaxMusicTool
---

# MiniMax music

One **multi-operation tool** — pick the right operation by user intent. Multi-op tools expose an `action` discriminator; pass `action:` as the first argument, alongside the operation-specific params.

## Operations at a glance

| When the user wants…                              | Operation           | Required params                             |
|--------------------------------------------------|---------------------|---------------------------------------------|
| A finished instrumental / vocal track            | `compose`           | `prompt` and/or `lyrics` (at least one)     |
| Lyrics — fresh, from a topic / style             | `write_lyrics`      | `prompt`                                    |
| Lyrics — re-write the existing ones              | `edit_lyrics`       | `lyrics` + `prompt`                         |

## Calling

### `compose`

```
minimax_music(action: "compose", prompt: "lofi hip-hop, rainy night, warm Rhodes", lyrics: "[Verse]\nCity lights blur past", output_format: "url", filename: "midnight-lofi")
```

- `prompt` — style / mood description (max 2000 chars). **Optional only when `lyrics` is supplied.** Empty prompt + empty lyrics = the tool refuses.
- `lyrics` — lyrics to sing (max 3500 chars). **Optional.** Omit for instrumental.
- `output_format` — `url` (default; 24-hour MiniMax CDN URL) or `hex` (inline MP3 bytes; routed through the Local Asset Store, served from `/api/v1/assets/<token>.mp3`). Use `url` unless the operator explicitly wants the file to persist beyond 24 h without the Media Archive; `hex` is auto-archived on success.
- `filename` — stem; auto-appended with `.mp3`.

### `write_lyrics`

```
minimax_music(action: "write_lyrics", prompt: "song about a late-night coding marathon that turned into a friendship", filename: "midnight-coders")
```

- `prompt` — topic / style description (max 2000 chars). **Required.**
- `filename` — stem; auto-appended with `.lyrics.txt` (the lyrics tool writes text, not audio — the file is stored as the lyrics output, not an MP3).

### `edit_lyrics`

```
minimax_music(action: "edit_lyrics", prompt: "make every other line rhyme and switch to a hopeful tone", lyrics: "[Verse]\nGrey morning\nNo sound…", filename: "tides-rev2")
```

- `prompt` — rewrite instruction (max 2000 chars). **Required.** Specific beats "re-write in another style"; describe the transformation, not the destination.
- `lyrics` — existing lyrics to edit (1–3500 chars). **Required.**
- `filename` — same as `write_lyrics`.

## Per-operation requirements (the easy-to-get-wrong part)

| Param     | `compose`         | `write_lyrics`    | `edit_lyrics`     |
|-----------|-------------------|-------------------|-------------------|
| `prompt`  | required if `lyrics` is empty | required         | required          |
| `lyrics`  | optional          | ignored           | required          |
| `output_format` | optional (`url` / `hex`), apply only `compose` | n/a | n/a |
| `filename`| optional          | optional          | optional          |

> ⚠️ **`edit_lyrics` requires BOTH `lyrics` AND `prompt`.** Forgetting either makes the tool fail validation with a clear message — but it's wasteful quota. Don't propose an `edit_lyrics` call without confirming the existing lyrics.

> ⚠️ **`write_lyrics` ignores `lyrics`.** It generates fresh lyrics — passing `lyrics` is not an error, but it's misleading tooling. Don't include it.

## Settings (operator-scoped)

| Setting                        | Default | Notes |
|--------------------------------|---------|-------|
| `api_key`                      | —       | Required. Shared with image/speech/video. |
| `base_url`                     | `https://api.minimax.io` | Override only for China-region or private gateway. |
| `model`                        | `music-3.0` | Applies to `compose` only — `write_lyrics` and `edit_lyrics` don't take a `model`. Per https://platform.minimax.io/docs/guides/models-intro, `music-3.0` is the current default; `music-2.6` and `music-cover` are also accepted by the upstream endpoint. |
| `http_timeout_seconds`         | `180`    | `compose` only. Composition can take 60–180 s on slow networks; raise to 240–300 s if the operator sees cURL 28 errors. |
| `http_timeout_seconds_lyrics`  | `30`     | `write_lyrics` / `edit_lyrics`. Lyrics endpoint is pure text and finishes quickly. |

## Limits

- `prompt` max **2000 chars** (every operation).
- `lyrics` max **3500 chars** (`compose` / `edit_lyrics`).
- `filename` max **120 chars** (sanitised like the other tools; extension auto-overridden to `.mp3` for `compose`, `.lyrics.txt` for the lyrics ops).

## Rendering

### `compose`

The tool returns a `<audio>` element via the Media Archive URL (or `data:` URI in `output_format=hex` fallback). Echo `ToolResult.content` verbatim. Stats line is included — don't reformat it.

```html
Generated music (prompt: "lofi piano").

<audio controls preload="metadata" src="/api/v1/assets/<token>.mp3"></audio>

Echo the `<audio>` element above verbatim — its `src` is `/api/v1/assets/<token>.mp3` served by the Media Archive, not a relative filename (rewriting it breaks playback). Don't strip this sentence; it tells the chat UI to render the player inline. For the raw URL, read `ToolResult.data.asset_url`.
```

If `output_format=url`, the upstream CDN URL expires in 24 h. The Media Archive URL is stable. Always prefer surfacing the archive URL when it exists.

### `write_lyrics` / `edit_lyrics`

Returns plain text with a `song_title`. Echo as-is:

```
[Verse 1]
City lights blur past the bus stop glass
…

Song title: "Midnight Coder"
```

If the user wants the lyrics as a file, write them to a sidecar file or paste into the chat with a clear ````lyrics` code fence for syntax highlighting.

## Failure modes

- `Provide at least a `prompt` or `lyrics`.` — both empty on `compose`. Ask the user for one.
- `Prompt exceeds the 2000-character MiniMax limit.` — trim or chunk.
- `Lyrics exceed the 3500-character limit.` — same.
- `Provide a `prompt` describing the song (or pre-existing `lyrics`).` — `write_lyrics` without `prompt`.
- "`lyrics` is required for the edit_lyrics operation." — `edit_lyrics` without `lyrics`.
- `output_format must be "url" or "hex".` — typo on `compose`.
- `MiniMax returned no audio data.` — upstream returned empty; retry once.

## Don'ts

- **Don't call `edit_lyrics` without the existing lyrics** — you'll get a validation error and waste quota.
- **Don't pass a `model` to `write_lyrics` / `edit_lyrics`** — the tool ignores it (no per-op model param in the lyrics endpoint); the LLM-visible schema doesn't expose one for those ops, so don't try to invent one.
- **Don't ask for "music" without clarifying instrumental vs vocal.** If the user is silent on lyrics, default to instrumental (omit `lyrics`).
- **Don't retry on a successful call** — `compose` is rate-limited (60–180 s per call); one call per user request.
- **Don't fabricate song titles or verses** — if `write_lyrics` returns empty, say so.
- **Don't strip the "Echo the `<audio>` element above verbatim…" sentence** (compose only). It tells future turns (and other agents) to render the URL inline, not as a click-through link — and it spells out the verbatim-echo rule for callers that didn't load this skill.
