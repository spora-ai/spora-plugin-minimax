---
name: minimax-video
description: "Generate short video clips (4–15s) via MiniMax H3 multimodal video model. **Asynchronous** — the tool submits a task, polls status, and only returns when the upstream reports `succeeded` or `failed`. Use when the user asks for a 'video', 'clip', 'short', 'B-roll', 'social-media video', 'product demo', 'scene', or any motion content. Supports text-to-video, image-to-video (first/last-frame), and reference-based generation (images / video clips / audio). Use `enhance_prompt` to enrich the prompt first, `resume` to re-attach to a timed-out task, and `regenerate` to upsample a finished 768P clip to 2K."
license: MIT
compatibility: spora>=0.7 spora-plugin-minimax>=1.2
metadata:
  author: spora-ai
  version: "1.2"
allowed-tools: Spora\Plugins\MiniMax\Tools\MiniMaxVideoTool
---

# MiniMax video (H3)

Four operations on one tool. They share the same submit → poll → archive pipeline; the only difference is which v2 endpoint they hit and what the success envelope contains.

| Operation | Endpoint | Produces |
|---|---|---|
| `generate` (default) | `POST /v2/video_generation` | An MP4 (download URL is `task.content.url` on `succeeded`). |
| `resume` | `GET /v2/query/video_generation/{task_id}` | Picks up an in-flight task by id. |
| `enhance_prompt` | `POST /v2/h3_context_ir` | A structured, semantically enriched prompt (text only, no video). |
| `regenerate` | `POST /v2/video_regeneration` | A 2K upsampled MP4 from a previous 768P H3 output. |

## Calling

```
# Text-to-video
minimax_video_minimax(prompt: "a red fox drinking from a forest stream at golden hour, [Push in], slow-motion", duration_seconds: 6, resolution: "768P", aspect_ratio: "16:9")

# Image-to-video (first frame only)
minimax_video_minimax(prompt: "[Push in] The fox lifts its head, water dripping from its whiskers", first_frame_image: "https://your-cdn.example/fox.png")

# Image-to-video (first + last frame — controlled transition)
minimax_video_minimax(prompt: "[Time-lapse] The fox ages from cub to adult", first_frame_image: "https://your-cdn.example/fox-cub.png", last_frame_image: "https://your-cdn.example/fox-adult.png")

# Reference-to-video (style/character anchors)
minimax_video_minimax(prompt: "The character walks into a foggy alley, neon-lit, cinematic", reference_images: ["https://your-cdn.example/character-ref-1.png", "https://your-cdn.example/character-ref-2.png"])

# Regenerate a previous 768P output as 2K
minimax_video_minimax(action: "regenerate", task_id: "115334141465231360", base_video_url: "https://...output-768p.mp4", prompt: "...", first_frame_image: "...")
```

| Parameter | Required | Default | Notes |
|---|---|---|---|
| `action` | No | `"generate"` | `"generate"`, `"resume"`, `"enhance_prompt"`, or `"regenerate"`. |
| `prompt` | `generate`, `enhance_prompt` | — | Subject + camera + lighting + motion. **Camera-movement tags** like `[Pan left]`, `[Push in]`, `[Tracking shot]`, `[Shake]` are first-class tokens the model parses as directives — use them. Max 7000 chars (H3 cap). |
| `first_frame_image` | No | — | URL of the opening frame for image-to-video. Must be `http(s)://` or `mm_file://` — **never base64** (the 64 MB request body cap can't carry inline data). H3 caps: ≤30 MB, [256, 5760] px, aspect [0.4, 2.5]. Mutually exclusive with `reference_*`. |
| `last_frame_image` | No | — | URL of the ending frame for start-end-frame i2v. Pairs with `first_frame_image`. |
| `reference_images` | No | — | Up to 9 reference image URLs. Mutually exclusive with `first_frame_image` / `last_frame_image`. |
| `reference_videos` | No | — | Up to 3 reference video URLs (MP4 / MOV, H.264 / H.265, ≤50 MB each, [2, 15] s, [256, 5760] px). |
| `reference_audio` | No | — | Up to 3 reference audio URLs (WAV / MP3, ≤15 MB each, [2, 15] s). Must be accompanied by an image or video input (H3 rejects audio-only `content[]`). |
| `aspect_ratio` | No | `"16:9"` | One of `21:9`, `16:9`, `4:3`, `1:1`, `3:4`, `9:16`, `adaptive`. Required for text-only `generate` (cannot be `adaptive`). Auto-forced to `adaptive` whenever any image / video / audio reference is supplied (H3 derives the ratio from the input). |
| `duration_seconds` | No | `6` | Integer, 4–15 inclusive. |
| `resolution` | No | `"768P"` | `"768P"` or `"2K"`. `resume` accepts it (preferred) so a timed-out `generate` can be replayed verbatim. |
| `filename` | No | auto | Stem only; `.mp4` auto-appended. |
| `poll_timeout_seconds` | No | operator setting (900 s) | Override the total wait window for this call (10–3600). |
| `task_id` | `resume`, `regenerate` | — | The MiniMax task id from a previous call. |
| `base_video_url` | `regenerate` | — | The previous 768P output's URL (the `download_url` / `asset_url` from the original `generate` response). |

## Generation modes

The tool picks one of four modes based on the arguments — **no explicit mode flag**. Detection is automatic from the supplied parameters.

| Mode | Inputs | Typical use case |
|---|---|---|
| Text-to-video (`t2v`) | `prompt` only | Generate from a text description. |
| Image-to-video, first-frame (`i2v`) | `prompt` + `first_frame_image` | Bring a static image to life. |
| Image-to-video, first & last frame | `prompt` + `first_frame_image` + `last_frame_image` | Control the start AND end frame for a precise transition. |
| Reference-to-video (`r2v`) | `prompt` + `reference_images` / `reference_videos` / `reference_audio` | Anchor character / motion / voice / style from references. |

**Mutual exclusivity**: `first_frame_image` / `last_frame_image` cannot be combined with any `reference_*` field — H3 rejects the mix. Pick one mode per call.

## Aspect ratio rules (mode-dependent)

| Mode | `ratio` field sent upstream |
|---|---|
| Text-to-video | LLM-supplied value (must be one of `21:9`, `16:9`, `4:3`, `1:1`, `3:4`, `9:16`; `adaptive` falls back to `16:9`). |
| Image-to-video (with frame image) | Always `adaptive` (the input image drives it). Concrete values the LLM supplies are silently ignored — we force `adaptive` server-side. |
| Reference-to-video | `adaptive` by default; LLM may override with a concrete ratio. |

## Operations in detail

### `generate` — submit a new video task

Flow: `POST /v2/video_generation` (with `content[]`, `duration`, `resolution`, `ratio`) → returns `task_id` → poll `GET /v2/query/video_generation/{task_id}` until `status` is `succeeded` or `failed` → archive the MP4 via Media Archive. All three HTTP steps happen inside one `minimax_video_minimax(...)` call from the LLM's perspective — there is no need to "poll again later".

### `resume` — re-attach to an in-flight task

Use when a previous `generate` returned `success: false` with `data.timed_out: true`. Pass the `task_id` from that response. The tool polls the existing task only — no re-submit, no new quota billed.

The `resume` operation ignores `prompt`, `duration_seconds`, `resolution`, and the image/reference inputs — the task is already in flight on MiniMax's side with the values from the original submit.

### `enhance_prompt` — pre-process the prompt via H3-Context-IR

H3-Context-IR is a non-video endpoint (`POST /v2/h3_context_ir`) that takes the same multimodal `content[]` as `generate` but returns a structured, semantically enriched prompt instead of a video. Useful when:

- The user supplied a one-line description that benefits from being expanded into a proper shot list.
- You want a detailed prompt before committing to a video-generation billing.

The response is `ToolResult.data.enhanced_prompt` — pass it verbatim as the `prompt` of a follow-up `generate` call. The skill instructs the LLM to preserve the enhanced prompt verbatim (no paraphrasing); H3 is sensitive to even small word changes.

`enhance_prompt` polls the shared `/v2/query/video_generation/{task_id}` endpoint and identifies the task by `task_type === 'h3_context_ir'` in the success envelope.

### `regenerate` — upsample a finished 768P H3 clip to 2K

The v2 regeneration endpoint (`POST /v2/video_regeneration`) takes the original generation's `content[]` verbatim, appends the previous 768P source as a `role: base_video` item, and submits with `resolution: "2K"`. Per the spec: "**The `text` must be the final prompt actually sent to the model when generating the 768P source video, not the original prompt from before H3-Context-IR processing**."

**`regenerate` rebuilds `content[]` from the same arguments you passed to `generate`** — pass back the exact `prompt`, `first_frame_image`, `last_frame_image`, `reference_*` you used originally, plus `base_video_url` (the previous 768P output's URL). The tool does NOT validate the rebuilt `content[]` against the original — H3 upstream returns 400 if it doesn't match. The LLM is the source of truth for the original args; pass them through verbatim.

`regenerate` is billed as a separate task — it does not "edit" the original.

## Settings (operator-scoped)

| Setting | Default | What it does |
|---|---|---|
| `api_key` | — (required) | MiniMax API key. Required. Shared with image / speech / music. |
| `base_url` | `https://api.minimax.io` | Override only for China-region (`https://api.minimaxi.com`) or a private gateway. |
| `model` | `MiniMax-H3` | Only `MiniMax-H3` is supported by the v2 endpoint. |
| `poll_interval_seconds` | `10` | Seconds between status polls. Below 5 s on a busy agent risks rate-limiting. |
| `poll_timeout_seconds` | `900` | **Total** wait window before the tool gives up. 2K regeneration on a busy day can take 8–12 min. The tool returns `task_id` on timeout so `resume` can keep waiting. |
| `submit_timeout_seconds` | `120` | Per-request timeout for the submit call (MiniMax queues the task server-side; the response can take >30 s). |

## When generation exceeds `poll_timeout_seconds`

If the upstream hasn't reported `succeeded` or `failed` before `poll_timeout_seconds` elapses, the tool returns:

```json
{
  "success": false,
  "content": "H3 task did not finish within 900s (task_id=task-slow). The task is still running on MiniMax's side and is billable. Increase `poll_timeout_seconds` and call `minimax_video_minimax(action: \"resume\", task_id: \"task-slow\")` to keep waiting, or abandon it and accept the billed quota.",
  "data": {
    "task_id": "task-slow",
    "status": "still_running",
    "timed_out": true,
    "prompt": "...",
    "first_frame_image": "...",
    "aspect_ratio": "...",
    "duration_seconds": 6,
    "resolution": "768P"
  }
}
```

The task is **still billable** on MiniMax's side. Surface this to the user and offer the choice: keep waiting (call `resume`) or abandon.

If the upstream reports `failed`, the tool surfaces the message verbatim:

```
H3 task failed (code=1026): video description contains sensitive content
```

## Limits (H3 v2)

- `prompt` max **7000 characters** (H3 hard cap).
- `duration_seconds` integer, **4–15** (was 6/10 enum in v1).
- `resolution` `"768P"` or `"2K"` (uppercase P on `768P`).
- `aspect_ratio` one of `adaptive`, `21:9`, `16:9`, `4:3`, `1:1`, `3:4`, `9:16`.
- `first_frame_image` + `last_frame_image`: ≤2 total (one each).
- `reference_images` ≤9, `reference_videos` ≤3, `reference_audio` ≤3, mixed ≤12.
- `reference_audio` must be accompanied by an image or video input.
- All URLs: `http(s)://` or `mm_file://`. `data:` URIs are rejected client-side (the 64 MB request body cap can't carry inline base64).
- Per-file size caps: image ≤30 MB, video ≤50 MB, audio ≤15 MB.
- Image dimensions: [256, 5760] px, aspect [0.4, 2.5].
- Output MP4 download URL is time-limited (typically 1 hour) — the Media Archive stores a long-lived copy.

## Prompt craft — what matters

H3's video model responds strongly to **camera direction**. Generic prose yields generic shots; a one-line direction makes the difference:

```
# Avoid — vague
"A red fox in a forest"

# Better — subject + action + camera + light
"A red fox lapping water from a forest stream at golden hour, water dripping off whiskers, slow push-in shot, shallow depth of field"

# Best — explicit camera tags the model parses as directives
"[Push in] A red fox lapping water from a forest stream at golden hour, [Shallow DOF], [Slow motion], water dripping off whiskers"
```

Other useful camera tags: `[Pan left]`, `[Pan right]`, `[Tilt up]`, `[Dolly back]`, `[Tracking shot]`, `[Crane up]`, `[Static]`.

For image-to-video, the `prompt` describes the **motion** — the still supplies the look. Don't restate the subject identity in the prompt; describe camera + action + lighting.

For reference-to-video, the references supply character / motion / voice. The `prompt` describes the new scene context.

Avoid prompt bloat — keep under 500 chars when possible. Beyond that, the model attends less to the salient bits.

## Rendering

The tool returns a `<video>` element with the Media Archive URL. Echo `ToolResult.content` verbatim:

```html
Generated video (16:9) for prompt: "..."

<video controls preload="metadata" playsinline src="/api/v1/assets/<token>.mp4"></video>

task_id: …  resolution: 768P  duration: 6s

Echo the `<video>` element above verbatim — its `src` is `/api/v1/assets/<token>.mp4` served by the Media Archive, not a relative filename (rewriting it breaks playback). Don't strip this sentence; it tells the chat UI to render the player inline. For the raw URL, read `ToolResult.data.asset_url`.
```

The "Echo the `<video>` element above verbatim…" sentence tells future turns to render the URL inline and spells out the verbatim-echo rule. Don't strip it.

For the raw download URL, read `ToolResult.data.asset_url` — never re-extract from the markdown.

## Failure modes

- `MiniMax API key is not configured for this agent.` — `api_key` setting is empty at every scope. Edit the tool's settings; this error doesn't fall back to env vars.
- `<field> is required for the <operation> operation.` — caller didn't supply a required field for that op.
- `image-to-video (first_frame_image / last_frame_image) and reference-to-video (reference_*) are mutually exclusive…` — caller mixed frame images with references; pick one mode per call.
- `media URL must be http(s):// or mm_file:// …` — caller passed a `data:` URI; rejected client-side.
- `duration_seconds must be an integer between 4 and 15.` — caller passed an out-of-range integer.
- `MiniMax returned no task_id from /v2/video_generation.` — upstream didn't queue the task. Retry once; if it persists, the API key or region is wrong.
- `H3 task did not finish within <N>s.` — generation didn't finish within `poll_timeout_seconds`. The response carries `task_id` and `data.timed_out: true` — call `resume` to keep waiting or abandon.
- `H3 task failed (code=<n>): <reason>` — upstream flagged the prompt as unsafe or the model couldn't render the scene. Suggest re-wording (generic safe-prompt prompts trip the safety filter rarely, but explicit-violation content does).

## Don'ts

- **Don't combine `first_frame_image` with `reference_*`** — H3 forbids the mix and the tool rejects it client-side.
- **Don't base64-encode images / video / audio** — the 64 MB request body cap can't carry them. Pass `http(s)://` or `mm_file://` URLs only.
- **Don't use lowercase `p`** (`"768p"`, `"2k"`) — MiniMax's enum is uppercase `P` for `768P`; `2K` is mixed-case. The tool rejects lowercase values.
- **Don't pass `adaptive` for text-only `generate`** — the tool falls back to `16:9` automatically; better to pass the ratio you actually want.
- **Don't forget to pair `reference_audio` with an image or video** — H3 rejects audio-only `content[]`. The tool rejects it client-side.
- **Don't poll externally** — the tool polls internally up to `poll_timeout_seconds`. If you're tempted to call `minimax_video_minimax(...)` again to "check on it", don't — you've already issued another submit. Use `resume` with the existing `task_id` instead.
- **Don't paraphrase the enhanced prompt** — when calling `enhance_prompt` then `generate`, pass the `enhanced_prompt` from the first call verbatim into the second call's `prompt`. H3 is sensitive to small word changes.
- **Don't promise instant delivery** — generation takes 30 s to several minutes. The user sees a spinner; don't claim "ready" before the tool returns.
- **Don't abandon a timed-out task silently** — the task is still billable on MiniMax's side. Surface the choice (keep waiting via `resume` vs accept the cost) to the user.
- **Don't omit `base_video_url` for `regenerate`** — required; the tool rejects without it.
- **Don't change `content[]` between `generate` and `regenerate`** — pass back the same `prompt` / `first_frame_image` / `last_frame_image` / `reference_*`. H3's spec says "Any mismatch may prevent regeneration from producing the expected result."

## What this tool does not do

- **General video upscaling** — `regenerate` only upsamples H3 768P outputs to 2K. Arbitrary video → 2K is not supported.
- **Async callbacks** (`callback_url`) — not exposed. The tool polls synchronously.
- **AIGC watermark toggle** (`aigc_watermark`) — not exposed; defaults to upstream's value.

## Debugging

The tool emits `debug` / `info` / `warning` PSR-3 entries to the Spora logger (Monolog), visible in `storage/spora.log`. Each generation call produces:

- `debug` "MiniMaxVideoTool: generate dispatched" — mode (text_only / i2v_… / r2v), content item count, duration, resolution, ratio, prompt length.
- `info`  "MiniMaxVideoTool: generate submitted" — task_id, duration, resolution, ratio, content items.
- `debug` "MiniMaxVideoTool: enhance_prompt dispatched" — same shape as `generate dispatched`.
- `info`  "MiniMaxVideoTool: enhance_prompt submitted" — same shape as `generate submitted`.
- `debug` "MiniMaxVideoTool: regenerate started" — source_task_id, base_video_url.
- `info`  "MiniMaxVideoTool: regenerate submitted" — source_task_id, new_task_id, content_items, ratio, duration.
- `debug` "MiniMaxVideoTool: aspect ratio resolved …" — which mode-rule applied (t2v / i2v / r2v / text-only fallback) and the final ratio.
- `debug` "MiniMaxVideoTool: POST submit" / "… submit accepted" — endpoint, model, body shape (no URL contents), returned task_id.
- `error` "MiniMaxVideoTool: submit returned no task_id" — endpoint, model, response shape (synthetic exception when upstream is missing the id).
- `info`  "MiniMaxVideoTool: poll loop started" — task_id, interval, poll_timeout, expect_kind.
- `debug` "MiniMaxVideoTool: still processing, sleeping" — per-poll, includes current status.
- `warning` "MiniMaxVideoTool: unexpected task_type on success" — sanity check that the submitted task came back as the expected `task_type`.
- `debug` "MiniMaxVideoTool: archiving result" — task_id, kind, download URL.

Enable `debug` level in the operator log config to see them.
