# spora-plugin-minimax

Spora plugin: minimax. The full reference (install, configuration,
per-tool parameters, development) has moved to the docs site:

**[docs.spora-ai.com/develop/plugins/reference/minimax](https://docs.spora-ai.com/develop/plugins/reference/minimax)**

See the docs site for the canonical reference. The README previously
in this repo has been migrated there.

## Archived-output filenames

When a MiniMax tool (`image`, `video`, `music`, `speech`) successfully
ingests its output into the Media Archive, the row is persisted with a
human-readable filename that downstream surfaces — download links, the
Media Archive detail page, the `asset_url` query parameter — all
reflect.

### LLM-supplied `filename` (preferred)

Every MiniMax tool accepts an optional `filename` ToolParameter. When
the agent supplies one, that name is sanitised (path components
stripped, only `[A-Za-z0-9._-]` kept, length capped at 240 chars) and
returned with the tool's canonical extension appended. A wrong
extension on the user input (e.g. `.jpg` for music) is overridden to
the canonical one (`.mp3`). The result is **never** made unique — two
ingests of the same user-named asset land in the Media Archive with the
same display name, which is the intended behaviour.

Examples:

| LLM input | Persisted filename |
|---|---|
| `sunset-at-the-beach` | `sunset-at-the-beach.png` |
| `track.mp3` (music) | `track.mp3` |
| `song.txt` (music) | `song.mp3` *(wrong ext overridden)* |
| `../../etc/passwd.png` | `etcpasswd.png` *(paths stripped)* |
| `Track.MP3` (music) | `Track.mp3` *(case-normalised)* |

### Generated fallback

When the agent doesn't supply a `filename`, the plugin slugifies the
prompt (lower-cased, transliterated to ASCII via `Transliterator` or
`iconv`, non-alphanumerics replaced with `-`, length capped at 60 chars
on a word boundary) and prepends the kind tag. The slug becomes the
filename stem:

| Prompt | Persisted filename |
|---|---|
| `a red fox` | `minimax-image-a-red-fox.png` |
| `Sonnenuntergang am Strand` | `minimax-image-sonnenuntergang-am-strand.png` |
| `🌅🌊` (no ASCII chars) | `minimax-image.png` *(prefix fallback)* |
| *(empty)* | `minimax-image.png` |

Filenames are not unique-enforced (`media_assets.filename` is plain
`string(255)`, no unique index). The same prompt ingested twice
produces the same name, which is what the operator wants — readable,
sortable, predictable.
