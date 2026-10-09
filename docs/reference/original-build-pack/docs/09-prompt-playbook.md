> HISTORICAL SOURCE ONLY. Superseded by the supermarket-only documents in the workspace root and docs/. Do not follow this file as current project instructions.

# 09 — Prompt Playbook (Codex / ChatGPT)

## Golden rules
1. **One task per session.** Use one item from `06-task-list.md`.
2. **Give context, not the whole project.** Codex reads `AGENTS.md` by itself. In ChatGPT web, paste `AGENTS.md` + the feature spec + only the schema/API parts it needs.
3. **Plan first, code second.** Make the AI list the files it will touch before writing.
4. **Run the tests every time** and paste failures back.
5. **Commit after every green task** (`git commit`). If a session goes wrong, `git restore` beats arguing with the AI.
6. **Read every line that touches** money, stock, login/tokens, tenant isolation, and payment callbacks. Ask the AI to explain any line you do not understand.

## Session starter (copy, fill the brackets)
```
Read AGENTS.md. Task: [S3 Products & batches API]. Follow docs/features/[F02-products-batches.md],
docs/03-database-schema.md (products, product_batches, stock_movements) and docs/04-api-contract.md (Products).
First reply with: (1) your plan in 5-8 lines, (2) the files you will create/change, (3) anything unclear.
Do not write code until I say "go".
Progress so far:
[paste the last 5-10 lines of the Progress log]
```

## After "go"
```
Go. Implement only this task. Write the tests listed in the spec acceptance criteria and docs/08-test-checklist.md.
Run php artisan test (and npm run test if you touched the frontend). Report: what you built, test results,
any assumptions (add them to docs/07-decisions.md), and anything you left undone.
```

## Review prompt (run after each task, ideally in a fresh chat)
```
Review the changes in this branch against AGENTS.md "Hard rules" and docs/02-business-rules.md.
Check specifically: tenant isolation, money as integers, stock changes through StockService with a movement row and lockForUpdate,
validation, permission checks, N+1 queries, missing tests. List problems by severity. Do not rewrite code unless I ask.
```

## Bug prompt
```
Bug: [what you did, what you expected, what happened, exact error message].
Relevant files: [paths]. First explain the likely cause and how to confirm it. Then propose the smallest fix and a test that would have caught it.
```

## Contract change prompt
```
We need to change [field/endpoint]. Update docs/04-api-contract.md first, show me the diff, then update backend, frontend and tests to match.
```

## Spec-writing prompt (for new features)
```
Write a feature spec in the same format as docs/features/F03-pos-sale.md for [feature]. Include flow, edge cases, acceptance criteria with concrete numbers.
Ask me up to 5 questions first if rules are missing.
```

## Warning signs (stop and ask)
- AI invents endpoints/fields not in the contract, or adds packages.
- AI "fixes" a failing test by deleting or weakening it.
- AI puts a calculation in React, or accepts `business_id` from the request.
- AI uses floats for money or edits `qty_remaining` directly.
- Large diffs for small tasks.

## Working from shared computers
Keep everything in Git (push after each task). Use GitHub Codespaces or a fresh clone + `composer install` / `npm ci` on any machine; `.env` secrets stay out of Git (keep a private copy of `.env.example` values you need).
