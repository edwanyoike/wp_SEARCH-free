# Release Notes — Project Steering

## Required Step: Customer-Facing Release Notes

Every plugin in this workspace must keep a `releasenotes/` directory at its root, alongside its developer-facing changelog (`changelog.txt` / `readme.txt`).

**On every major version bump, add a release note.** In this codebase "major" means the `X.Y` (major.minor) line, not just the leading number — nearly every plugin here has stayed on major version `1` (or, for Turbo Search for WooCommerce, jumped `X` only rarely) for its entire life, so `X.Y` is where real, announceable feature milestones actually land. Patch releases (`X.Y.Z` → `X.Y.(Z+1)`) do **not** get their own release note; fold their user-visible effect into the note for the `X.Y.0` release they belong to, or amend that note if the patch changes user-facing behavior materially.

### File naming and location

- Directory: `releasenotes/` at the plugin root (same level as `changelog.txt` / `readme.txt`).
- One file per major.minor line: `releasenotes/X.Y.0.md` (e.g. `releasenotes/1.3.0.md`).

### Voice and content rules

- Audience is the store owner using the plugin, not a developer. No filter names, class names, hook names, SQL, or internal architecture.
- Lead with what changed for them and why it matters, not how it was implemented.
- Group into short sections when useful: **New**, **Improved**, **Fixed**, **Security** — omit any section with nothing to say.
- Skip internal-only changes (refactors, dev-only filters/actions, test additions) entirely — they have no customer-facing content.
- Security fixes: describe the customer benefit ("hardened against X") without publishing exploit detail.
- Keep it short — a handful of bullets, not a wall of text. One paragraph of context at most if the release needs it.

### Source of truth

Derive each note from the plugin's own `changelog.txt` or `readme.txt` "Changelog" section — that is the authoritative, dated record of what shipped in each version. Do not invent features that aren't in the changelog.

### When adding a new major.minor version to a plugin going forward

1. Ship the version and update `changelog.txt` / `readme.txt` as usual (developer-facing, unchanged process).
2. Add `releasenotes/X.Y.0.md` following the rules above before considering the release done.
