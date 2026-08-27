---
name: add-reference-repository
description: Clones a reference repository into `.references/<name>` and prepares it for reuse. Use when adding, refreshing, or bootstrapping a local reference repo from GitHub or another git remote.
---

# Add Reference Repository

## Quick Start

Create or reuse the current repo's `.references/` directory, then clone the requested repository into `.references/<name>`.

If the target path already exists, do not overwrite it blindly. Inspect the existing repo first and only replace it if the user explicitly asks.

## Workflow

1. Resolve the target name and clone URL.
2. Before adding a new entry, check it isn't stale: `curl -s "https://api.github.com/repos/<owner>/<repo>" | python3 -c "import json,sys; d=json.load(sys.stdin); print(d['pushed_at'], d['archived'], d['stargazers_count'])"`. Skip anything archived or with no push in ~2 years unless it's uniquely relevant despite being dormant — say so explicitly if so.
3. Ensure `.references/` exists at the root of the current repository (git-ignored — never commit a clone).
4. Clone the remote read-only, shallow: `git clone --depth 1 <url> .references/<name>`.
5. If the cloned repository does not contain an `AGENTS.md`, create one from the local repo's `AGENTS.md` or a minimal repository-specific template.
6. Report the created path and the remote URL used.
7. Update the workspace `reference-repos.md` index when the curated set of references changes — one row per repo, with a one-line "why" specific to this workspace's problem, not a generic description.

## Notes

- Use the repository root of the current workspace, not the agent's own home directory.
- Keep reference repositories isolated under `.references/` so they can be inspected without affecting the main repo.
- When a reference repo already exists, treat it as a reusable local fixture rather than recreating it.
- Bring back the **pattern**, not the code — cite what you found ("X resolves `xs:choice` by ...") and implement independently. Never copy code verbatim, even from a permissively-licensed repo; check the repo-specific independence/attribution rules in the workspace's own `AGENTS.md`.
