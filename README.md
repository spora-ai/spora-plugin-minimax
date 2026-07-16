# spora-plugin-minimax

Spora plugin: minimax. The full reference (install, configuration,
per-tool parameters, development) has moved to the docs site:

**[docs.spora-ai.com/develop/plugins/reference/minimax](https://docs.spora-ai.com/develop/plugins/reference/minimax)**

See the docs site for the canonical reference. The README previously
in this repo has been migrated there.

## Archived-output filenames

When a MiniMax tool (`image`, `video`, `music`, `speech`) successfully
ingests its output into the Media Archive, the row is persisted with a
deterministic, human-readable filename of the shape
`minimax-<tool>-<UTC-yyyy-mm-dd-hhmmss>-<8-hex-chars>.<ext>`, e.g.
`minimax-image-2026-07-15-143022-1a2b3c4d.png`. The timestamp is the
UTC time of the call (so filenames sort chronologically across hosts in
different timezones) and the 8-char hex suffix is a per-call nonce that
guarantees uniqueness within the same second. Download links, the
Media Archive detail page, and the `asset_url` query parameter all
reflect this name.
