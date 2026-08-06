---
name: minimax-image-to-video
description: "Animate a still image into a short video clip via MiniMax H3 image-to-video. Use when the user asks to 'animate this', 'bring this image to life', 'turn this into a video', 'make a clip of this picture', or any motion-from-still workflow. For uploaded chat attachments, pass the `asset_id` surfaced inline above the image block to `minimax_video_minimax` — the plugin's resolver fetches the bytes server-side (Path D, no re-encoding, no fidelity loss). Fall back to generating a fresh still via `minimax_image_minimax` (Path B) only when no asset_id is in context."
license: MIT
compatibility: spora>=0.16 spora-plugin-minimax>=1.2
metadata:
  author: spora-ai
  version: "1.2"
allowed-tools: Spora\Plugins\MiniMax\Tools\MiniMaxImageTool, Spora\Plugins\MiniMax\Tools\MiniMaxVideoTool
---

# MiniMax image-to-video workflow

H3 supports first-frame image-to-video: the model takes a still image and animates it according to a text prompt describing the desired motion. This skill documents the four paths to feed an image into `minimax_video_minimax(first_frame_image: ...)`:

1. **Externally-hosted image** — user pasted a public URL. Pass directly.
2. **Generated image** — user wants a video of a freshly generated still. Chain `minimax_image_minimax` → `minimax_video_minimax`.
3. **Uploaded chat attachment** — pass the surfaced `asset_id`; the plugin resolves bytes server-side (Path D, see below).
4. **Multimodal first/last frame** — supply both URLs for start-end-frame animation.

The plugin accepts `first_frame_image` as either a UUID, a `/api/v1/assets/<uuid>.<ext>` opaque URL, a `data:` URI, or an `http(s)://` / `mm_file://` URL. UUIDs and opaque URLs are resolved server-side before the URL validator runs.

## When to load

Load this skill when the user request matches any of:

- "animate this image" / "make this picture move" / "bring this to life"
- "turn this into a video" / "make a clip of this"
- "generate a video of [subject]" — when the user implies the still should be generated first
- "make a video starting from this frame"
- "image-to-video" / "i2v"

If the user just wants a plain text-to-video (no still anchor), don't load this skill — go straight to `minimax_video_minimax` with `prompt` only.

## Calling

### Path A — externally-hosted image (the user pasted a public URL)

If the user already provided a publicly-reachable image URL, pass it directly. This is the highest-fidelity path — no re-encoding, no resolver round-trip.

```
minimax_video_minimax(
  prompt: "[Push in] The fox lifts its head and looks into the camera, wind rustling its fur, golden-hour lighting",
  first_frame_image: "https://cdn.example.com/uploads/fox-still.png",
  duration_seconds: 6,
  resolution: "768P",
  aspect_ratio: "adaptive",
)
```

The `aspect_ratio: "adaptive"` is critical — H3 derives the output ratio from the input image for image-to-video mode. Setting a concrete ratio like `16:9` is silently ignored.

URL hygiene: only `http://`, `https://`, and `mm_file://` are accepted as raw URLs. The plugin also accepts `data:` URIs (≤ ~50 MB after base64) for inline bytes. Media Archive UUIDs and `/api/v1/assets/<uuid>.<ext>` paths are resolved to data URIs server-side via Path D — the resolver runs before the URL validator, so you don't need to translate them yourself.

### Path D — uploaded chat attachment (asset_id surfaced inline)

**This is the primary path for user uploads.** When the user attaches an image to chat, spora-core's `MessageHistoryBuilder` (≥0.16) injects an identity prefix above the image block in your context:

```
[Attached asset_id=01928e9d-…-… (filename: fox.png, type: image/png, size: 2.3 MB) — local URL: /api/v1/assets/01928e9d-….png]
```

Pass that asset_id (or the local URL — both forms resolve identically) to `minimax_video_minimax`. The plugin's resolver fetches the bytes through spora-core's `MediaAssetReader`, base64-encodes them, and forwards the resulting `data:` URI to H3. No re-encoding, no fidelity loss, no public URL exposure.

```
minimax_video_minimax(
  prompt: "[Push in] The fox lifts its head and looks into the camera",
  first_frame_image: "01928e9d-…-…",
  duration_seconds: 6,
  resolution: "768P",
  aspect_ratio: "adaptive",
)
```

The two acceptable reference forms:

- Bare UUID (preferred) — `first_frame_image: "01928e9d-…-…"`
- Opaque URL — `first_frame_image: "/api/v1/assets/01928e9d-….png"`

Both resolve to the same `data:` URI before the URL validator runs. Use the bare UUID form when grepping from the inline prefix; the opaque URL form works too and is equivalent.

Resolution mechanics (for context, not action):

- `data_url` storage (DB BLOB) → `data:<mime>;base64,<payload>` (inlined)
- `local` storage (disk) → `data:<mime>;base64,<payload>` (loaded + encoded)
- `external` storage (CDN-backed) → the original source URL is forwarded as-is (MiniMax fetches it directly)
- 50 MB hard cap on the resulting `data:` URI; oversized payloads fail with an actionable error (downscale the image or upload a smaller version)
- Resolution is logged at debug level (`minimax.media-archive-resolved` / `minimax.media-archive-source-forwarded`) — never echoed back to the LLM

When to skip Path D:

- The user didn't upload an image AND didn't paste a URL — generate a fresh still with Path B.
- The attachment metadata in the prefix says the image is unusually large (>~37 MB raw, the maxBytesUnderCap threshold); resize or recompress before retrying, or fall back to a public URL (Path A).

### Path B — generated image (no asset_id in context)

Use this when the user wants a video of a freshly generated still. Path D requires an uploaded asset_id; if the user describes a subject instead of uploading (e.g. "animate a red fox"), there's no UUID to resolve, so chain `minimax_image_minimax` → `minimax_video_minimax`:

```
# Step 1 — generate the still
result = minimax_image_minimax(prompt: "a stoic red fox standing in a snowy forest at dawn, painterly", aspect_ratio: "16:9", filename: "fox-still")

# Step 2 — animate the still. Take the first URL from ToolResult.data.image_urls.
minimax_video_minimax(
  prompt: "[Push in] The fox breathes in, fog coming from its mouth, snowflakes drifting past",
  first_frame_image: result.data.image_urls[0],
  duration_seconds: 6,
  resolution: "768P",
  aspect_ratio: "adaptive",
)
```

If `minimax_image_minimax` returned multiple images, pick the one the user wanted (usually `[0]` unless the user asked for variations). When in doubt, ask before generating — the still is the foundation of the clip.

Do not use Path B to substitute for Path D when an attachment is present. Path B regenerates a similar still, which loses the user's exact image. If Path D fails (oversized asset, asset not found), surface the error to the user instead of silently regenerating.

### Path C — multimodal first/last frame

For start-end-frame animation (specifying both an opening and closing still), supply both URLs. Both must pass the URL hygiene rules above (or be Path D UUIDs / opaque URLs).

```
minimax_video_minimax(
  prompt: "[Dolly zoom] The flower opens, petals unfurling into full bloom",
  first_frame_image: "https://cdn.example.com/uploads/bud.png",
  last_frame_image:  "https://cdn.example.com/uploads/bloom.png",
  aspect_ratio: "adaptive",
)
```

`last_frame_image` requires `first_frame_image` (H3 pairs them); the tool rejects mismatches.

## Prompt craft for the motion

When the still supplies the look, the `prompt` should describe **motion + camera + lighting only**, not the subject identity. Bad prompts restate the subject ("a red fox stands in snow"); good prompts describe what changes:

```
# Bad — restates the still
"a red fox standing in a snowy forest"

# Good — motion + camera
"[Push in] The fox lifts its head, exhaling visible breath in the cold, snowflakes drifting past the lens"

# Best — explicit camera + action + light
"[Push in, Shallow DOF] The fox inhales, breath fogging the cold air; its ears twitch; backlit by golden-hour rim light, snowflakes drifting through the bokeh"
```

Useful camera tags: `[Push in]`, `[Pull out]`, `[Pan left]`, `[Pan right]`, `[Tracking shot]`, `[Static]`, `[Slow motion]`, `[Shallow DOF]`.

## Limits (H3 input caps for first_frame_image / last_frame_image)

- Single-file size: ≤30 MB (H3 upstream). The plugin's Path D resolver caps at ~37.5 MB raw bytes to keep the resulting `data:` URI under the 50 MB request-body ceiling.
- Width / height: [256, 5760] px.
- Aspect ratio (w/h): [0.4, 2.5] — outside this range H3 rejects upstream.
- Format: JPG, JPEG, PNG, WEBP, HEIC, HEIF.

If the user supplied an image outside these bounds (e.g. a tall screenshot), downscale or convert before passing to `minimax_video_minimax`. The chat UI's Media Archive may already enforce sane defaults — check the attachment metadata in the inline `[Attached asset_id=…]` prefix.

## Don'ts

- **Don't pass a Spora Media Archive URL** (`/api/v1/assets/<token>.<ext>`) raw without letting the resolver run. The plugin DOES accept the opaque URL form and resolves it via Path D — just don't try to base64-encode it manually or `curl` the local server. The resolver handles the round-trip.
- **Don't substitute Path B for Path D when the user uploads an image** — Path B regenerates a similar still, losing the user's exact image. Use the asset_id from the inline prefix; only fall back to Path B if the user describes a subject without uploading.
- **Don't set `aspect_ratio` to anything other than `adaptive` for i2v** — H3 forces `adaptive` server-side; sending `16:9` doesn't error but is silently ignored, wasting a request parameter that could trip up future migrations.
- **Don't pass `reference_*` alongside `first_frame_image`** — i2v and r2v are mutually exclusive. If you need a style/character anchor on top of the input image, fall back to plain t2v with the references.
- **Don't use a CDN URL that might expire** — MiniMax may fetch the image asynchronously. Use a stable URL (S3 / GCS public object, a CDN with long-lived URLs). For short-lived URLs from `minimax_image_minimax`, prefer the `image_urls[0]` returned in the same session.
- **Don't add a `last_frame_image` without a matching `first_frame_image`** — H3 requires them paired; the tool rejects mismatches.
- **Don't claim "done" before the tool returns** — H3 generation takes 30 s to several minutes; the user sees a spinner. Tell them what you're doing ("generating… usually 30–120 s") and only claim success after `minimax_video_minimax` returns.
- **Don't try to parse the asset bytes yourself** — the `[Attached asset_id=…]` prefix is the LLM-facing pointer. The resolver fetches the bytes; you never see them in your context.

## Failure modes

- `Media asset <uuid> not found in the Spora Media Archive.` — the resolver couldn't read the asset (wrong UUID, asset deleted, or per-user scope mismatch via the `media` scope setting). Verify the asset_id from the inline `[Attached asset_id=…]` prefix, or call `media:search` to discover the right UUID, or paste a public URL (Path A).
- `Media asset <uuid> is <N> MB, exceeds the 50 MB data URI cap. Use a downscaled image or paste a public URL.` — the attachment is too large for Path D's inline resolver. Resize / recompress, or paste a public URL (Path A).
- `media URL must be http(s)://, mm_file://, or a data: URI (got: ...)` — you passed a URL with an unsupported scheme (ftp://, file://, etc.) or an empty string. Re-check the URL.
- `MiniMax H3 task failed (code=1026): video description contains sensitive content` — your prompt tripped the safety filter. Rephrase (replace specific violence / brand references with cinematic abstractions) and retry.
- `H3 task did not finish within <N>s` — the generation didn't finish in time. Call `minimax_video_minimax(action: "resume", task_id: ...)` on the next turn to keep waiting.
- `MiniMax succeeded but the response did not include a download URL` — extremely rare; the upstream succeeded but the response was malformed. Retry once; if it persists, surface.
- Image is too small / wrong aspect ratio — H3 rejects upstream with a 400. Ask the user for a higher-resolution version or generate a new still at the right shape.

## After the video renders

Echo `ToolResult.content` from the `minimax_video_minimax` call verbatim — it already carries the `<video>` element + the trailing "Echo the `<video>` element above verbatim…" sentence that tells the chat UI to render the player inline. Don't strip that sentence; without it the chat UI drops the player.

For the raw URL (download / re-use), read `ToolResult.data.asset_url` (Media Archive long-lived URL) or `ToolResult.data.download_url` (upstream CDN, expires quickly).
