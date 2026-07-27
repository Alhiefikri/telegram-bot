# Superpowers

This repository uses [obra/superpowers](https://github.com/obra/superpowers)
as its primary software-development methodology. The project-local installation
is pinned through `skills-lock.json` and currently contains the complete
Superpowers 6.2.0 skill set.

## Bootstrap rule

Every root agent must load `using-superpowers` before its first response or
action for a user task. If another skill may apply, load it before exploring,
asking questions, planning, or editing. Process skills take priority over domain
and implementation skills.

Dispatched subagents follow the task-specific skill instructions supplied by
their coordinator. They do not repeat the root-session bootstrap unless their
assignment explicitly requires it.

## Jcode tool mapping

Superpowers describes actions rather than harness-specific tool names. In Jcode,
map those actions as follows:

| Superpowers action | Jcode tool |
| --- | --- |
| Invoke or load a skill | `skill_manage` with `action: load` |
| Create or update a todo list | `todo` |
| Dispatch or communicate with subagents | `swarm` |
| Read, write, edit, or patch files | `read`, `write`, `edit`, `multiedit`, or `apply_patch` |
| Search files or code | `agentgrep` |
| Run shell commands | `bash` |
| Fetch or search the web | `webfetch` or `websearch` |

When a Superpowers skill refers to a generic subagent, use a Jcode swarm agent
with an appropriate task prompt and preserve the review gates required by the
skill.

## Installed Superpowers skills

- `brainstorming`
- `dispatching-parallel-agents`
- `executing-plans`
- `finishing-a-development-branch`
- `receiving-code-review`
- `requesting-code-review`
- `subagent-driven-development`
- `systematic-debugging`
- `test-driven-development`
- `using-git-worktrees`
- `using-superpowers`
- `verification-before-completion`
- `writing-plans`
- `writing-skills`

## Replaced conflicting skills

The following older skills were removed because they duplicated or competed
with the Superpowers workflow:

| Removed skill | Superpowers replacement |
| --- | --- |
| `ask-matt` | `using-superpowers` |
| `code-review` | `requesting-code-review` |
| `review` | `requesting-code-review` |
| `diagnosing-bugs` | `systematic-debugging` |
| `implement` | `executing-plans` and `subagent-driven-development` |
| `request-refactor-plan` | `brainstorming` and `writing-plans` |
| `tdd` | `test-driven-development` |
| `writing-great-skills` | `writing-skills` |

Domain modeling, issue tracking, handoff, research, UI design, deployment, and
project-operations skills remain installed because they complement rather than
replace Superpowers.

## Updating

Review upstream release notes before updating, then run the project-scoped
skills CLI and inspect the diff:

```bash
npx skills update -p -y
git diff -- .agents/skills agent/skills .commandcode/skills skills-lock.json
```

Keep `.agents/skills/` as the canonical active copy. Keep the installer-generated
`agent/skills/` variants for repository-supported harnesses; their frontmatter
is intentionally adapted and is not byte-for-byte identical to `.agents/skills/`.
Keep `.commandcode/skills/` as symlinks to `.agents/skills/`. Do not commit
generated skill copies for agent harnesses this repository does not use.
