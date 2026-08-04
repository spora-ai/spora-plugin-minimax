---
name: minimax-image-to-video
description: "Animate a still image into a short video clip via MiniMax H3 image-to-video. Use when the user asks to 'animate this', 'bring this image to life', 'turn this into a video', 'make a clip of this picture', or any motion-from-still workflow. Chains `minimax_image` (or an existing uploaded image) → `minimax_video` with `first_frame_image` + `aspect_ratio: \"adaptive\"`. If the user supplied their own image, the LLM already has its Media Archive URL in context — no re-encoding required."
license: MIT
compatibility: spora>=0.7 spora-plugin-minimax>=1.2
metadata:
  author: spora-ai
  version: "1.0"
allowed-tools: Spora\Plugins\MiniMax\Tools\MiniMaxImageTool, Spora\Plugins\MiniMax\Tools\MiniMaxVideoTool
---

# MiniMax image-to-video workflow

H3 supports first-frame image-to-video: the model takes a still image and animates it according to a text prompt describing the desired motion. This skill documents the two paths to feed an image into `minimax_video(first_frame_image: ...)`:

1. **Uploaded image** — the user attached a picture to the chat. Its Media Archive URL is already in the conversation context.
2. **Generated image** — the user wants a video of a freshly generated image. Call `minimax_image` first, then take its output URL.

## When to load

Load this skill when the user request matches any of:

- "animate this image" / "make this picture move" / "bring this to life"
- "turn this into a video" / "make a clip of this"
- "generate a video of [subject]" — when the user implies the still should be generated first
- "make a video starting from this frame"
- "image-to-video" / "i2v"

If the user just wants a plain text-to-video (no still anchor), don't load this skill — go straight to `minimax_video` with `prompt` only.

## Calling

### Path A — uploaded image (the user attached a picture)

The attachment's Media Archive URL is already in the conversation context (Spora renders it as `<media src="/api/v1/assets/<token>.<ext>">` in the message). Pass that URL straight through to `minimax_video`:

```
minimax_video(
  prompt: "[Push in] The fox lifts its head and looks into the camera, wind rustling its fur, golden-hour lighting",
  first_frame_image: "/api/v1/assets/9b8c7d6e-1234-5678-9abc-def012345678.png",
  duration_seconds: 6,
  resolution: "768P",
  aspect_ratio: "adaptive",
)
```

The `aspect_ratio: "adaptive"` is critical — H3 derives the output ratio from the input image for image-to-video mode. Setting a concrete ratio like `16:9` is silently ignored.

### Path B — generated image (the user wants a video of a fresh image)

Two-step chain:

```
# Step 1 — generate the still
result = minimax_image(prompt: "a stoic red fox standing in a snowy forest at dawn, painterly", aspect_ratio: "16:9", filename: "fox-still")

# Step 2 — animate the still. Take the first URL from ToolResult.data.image_urls.
minimax_video(
  prompt: "[Push in] The fox breathes in, fog coming from its mouth, snowflakes drifting past",
  first_frame_image: result.data.image_urls[0],
  duration_seconds: 6,
  resolution: "768P",
  aspect_ratio: "adaptive",
)
```

If `minimax_image` returned multiple images, pick the one the user wanted (usually `[0]` unless the user asked for variations). When in doubt, ask before generating — the still is the foundation of the clip.

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

## Limits (H3 input caps for first_frame_image)

- Single-file size: ≤30 MB.
- Width / height: [256, 5760] px.
- Aspect ratio (w/h): [0.4, 2.5] — outside this range H3 rejects upstream.
- Format: JPG, JPEG, PNG, WEBP, HEIC, HEIF.

If the user supplied an image outside these bounds (e.g. a tall screenshot), downscale or convert before passing to `minimax_video`. The chat UI's Media Archive may already enforce sane defaults — check the attachment metadata.

## Don'ts

- **Don't base64-encode the image** — H3 caps the request body at 64 MB. Inline base64 inflates by ~33% and any non-trivial image will exceed the cap. Always pass the Media Archive URL (which starts with `/api/v1/assets/`) or a public URL.
- **Don't re-encode the image** — pass the URL through verbatim. The Media Archive serves a stable URL that's safe to reference across turns.
- **Don't set `aspect_ratio` to anything other than `adaptive` for i2v** — H3 forces `adaptive` server-side; sending `16:9` doesn't error but is silently ignored, wasting a request parameter that could trip up future migrations.
- **Don't pass `reference_*` alongside `first_frame_image`** — i2v and r2v are mutually exclusive. If you need a style/character anchor on top of the input image, that's a different (currently unsupported) flow — fall back to plain t2v with the references.
- **Don't use a CDN URL that might expire** — if the user uploaded an image, use the Media Archive URL (long-lived). If the user pasted an external URL, use it directly but warn them that the link must stay reachable.
- **Don't add a `last_frame_image` without a matching `first_frame_image`** — H3 requires them paired; the tool rejects mismatches.
- **Don't claim "done" before the tool returns** — H3 generation takes 30 s to several minutes; the user sees a spinner. Tell them what you're doing ("generating… usually 30–120 s") and only claim success after `minimax_video` returns.
- **Don't recurse the chain** — you don't need to call `minimax_image` to "preview" the image if the user supplied it. The attachment URL is already the preview.
- **Don't skip Path A** — when an attachment is present, prefer Path A over Path B. Path B (regenerate) loses fidelity vs. the user's original.

## Failure modes

- `media URL must be http(s):// or mm_file:// …` — you passed a `data:` URI or an empty string. Re-check the attachment.
- `MiniMax H3 task failed (code=1026): video description contains sensitive content` — your prompt tripped the safety filter. Rephrase (replace specific violence / brand references with cinematic abstractions) and retry.
- `H3 task did not finish within <N>s` — the generation didn't finish in time. Call `minimax_video(action: "resume", task_id: ...)` on the next turn to keep waiting.
- `MiniMax succeeded but the response did not include a download URL` — extremely rare; the upstream succeeded but the response was malformed. Retry once; if it persists, surface.
- Image is too small / wrong aspect ratio — H3 rejects upstream with a 400. Ask the user for a higher-resolution version or generate a new still at the right shape.

## After the video renders

Echo `ToolResult.content` from the `minimax_video` call verbatim — it already carries the `<video>` element + the trailing "Echo the `<video>` element above verbatim…" sentence that tells the chat UI to render the player inline. Don't strip that sentence; without it the chat UI drops the player.

For the raw URL (download / re-use), read `ToolResult.data.asset_url` (Media Archive long-lived URL) or `ToolResult.data.download_url` (upstream CDN, expires quickly).
