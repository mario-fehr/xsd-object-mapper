# [Feature] implementation plan

Spec: `docs/specs/[YYYY-MM-DD]-[slug]-design.md`

## Global constraints

- PHP `^8.4`, `declare(strict_types=1)` in every new file.
- Every new/changed class gets unit test coverage; run `vendor/bin/phpunit` after every task.
- Update `docs/construct-coverage.md` (and `docs/backlog.md` if a limitation is resolved) when a construct's support status changes.
- Commit convention: `[Slug] Short imperative summary` per task.

## File structure

- Create: `[path]`
- Modify: `[path]`
- Test: `[path]`

## Task 1: [name]

**Files:**
- Modify: `[path]`
- Test: `[path]`

- [ ] **Step 1: Write the failing test**

[Test code / description]

- [ ] **Step 2: Run `vendor/bin/phpunit --filter [Test]` — verify it fails**

- [ ] **Step 3: Implement**

[What to change, minimal]

- [ ] **Step 4: Run `vendor/bin/phpunit --filter [Test]` — verify it passes**

- [ ] **Step 5: Commit**

```bash
git add [files]
git commit -m "[Slug] [summary]"
```

## Task 2: [name]

...

## Final verification

- [ ] `vendor/bin/phpunit` — full suite green
- [ ] `docs/construct-coverage.md` / `docs/backlog.md` updated if applicable
- [ ] Matches spec's Goals/Non-goals
- [ ] `/code-review` on the full diff
