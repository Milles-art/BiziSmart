> HISTORICAL SOURCE ONLY. Superseded by the supermarket-only documents in the workspace root and docs/. Do not follow this file as current project instructions.

# BiziSmart AI Build Pack

Drop this into the root of your repo (`AGENTS.md` at the root, `docs/` beside `/backend` and `/frontend`). Keep `BiziSmart_Technical_Documentation_v3_5.pdf` in `docs/reference/` as background.

**Reading order for you:** 09 (how to prompt) -> 06 (what to do next) -> the feature spec -> 02/03/04 as needed.

**Using Codex:** it reads `AGENTS.md` automatically. Point it at the feature spec in your prompt.
**Using ChatGPT (web):** paste `AGENTS.md` plus the one feature spec plus only the schema/API sections it touches, at the start of each chat.

Rules that make AI-built code safe: small tasks, tests every time, you read every line that touches money, stock, login or tenant isolation.
