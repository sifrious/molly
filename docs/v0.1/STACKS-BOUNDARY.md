# Stacks boundary map (MME-5352 Workstream 1)

> **Historical.** This page records work from the v0.1 release. It is kept for context and is not current install guidance. For today's install steps, read [Getting started](../getting-started.md).

Date: 2026-09-21  
Molly baseline: `1c875ad` (`sifrious/molly` main)  
Extractor hosts (Studio, read-only — **not** runtime deps):

| Checkout | SHA | Role |
|---|---|---|
| `stacks-contract-mme-4935` | `270358572d9c5d062c229ee4d0adf41c87e5ead6` | Public-shaped contract DTOs (`WorkspaceReference`, `ExecutionProvenance`) |
| `dep-stacks` | `15916a7f12d01e883144ef6ccb8d4a12815b0a49` | Domain ID types / registry ideas (extraction reference only) |
| `stacks` | `45245dcb1cfeff968ee9bf963a26090187e3906e` | Full Stacks app — **do not** copy |

## Classification legend

1. **Internalize** — copy minimal behavior into Molly-owned code  
2. **Already Molly** — keep; no Stacks dependency  
3. **Remove** — delete if present (runtime/auth/docs that require private Stacks)  
4. **Future** — post-v0.1 packaging / optional sync (e.g. MME-5344)

## Inventory

| Surface | Finding | Class |
|---|---|---|
| `composer.json` / repositories | No Stacks package; no private VCS repos | **2** Already Molly |
| PHP `Stacks\\` imports | None in Molly tree | **2** |
| Service-provider bindings | None to Stacks | **2** |
| Config / env for Stacks auth | None required | **2** (keep absent) |
| `molly_tasks.workspace` / `molly_runs.workspace` | Absolute path text used as identity via `CreateTask` / models | **1** Internalize (MME-5343 bind) |
| `Sifrious\\Molly\\Workspace` (filesystem helper) | Path lease / digest helper — **not** canonical identity | **2** Keep; paths stay metadata |
| `Contracts\\TaskContract` / `RunIdentity` / `RepositoryIdentity` | Already carry `bloom_workspace_id`, `workspace_path`, `base_sha`, repository — contract layer ahead of persistence | **2** + wire persistence (**1**) |
| Bloom handoff UUIDs | `bloom_workspace_id` / handoff workspace UUIDs | **2** Already Molly/Bloom |
| Docs / QUICKSTART | No private Stacks clone/auth steps found | **2** |
| Bloom plugin drop | Surfaces.bundle — no Stacks | **2** |
| Tests/fixtures | Use temp absolute paths as execution roots | **1** Update to persist Molly identity + path metadata |
| Private Stacks Composer/auth docs | Not present | **3** N/A — keep search clean |
| Publish stacks-contract package (MME-5344) | Optional Stacks-side packaging | **4** Future — does not block Molly v0.1 |
| Full Stacks registry / CLI / reconciliation | Not used by Molly | **3** Do not copy |

## Required behavior to internalize (minimal)

Molly owns:

- Stable **project** identity (UUID; not a path)
- Stable **workspace** identity (UUID; survives path moves)
- **Repository** identity / remote identity (align with existing `RepositoryIdentity`)
- **Checkout** identity + kind
- **Revision** / base SHA (immutable starting revision)
- **Provenance** snapshot (path as observed metadata only)
- Fail closed when availability is `ambiguous` / missing revision

Foreshadowed types (PSR-4 under existing package — nested next to filesystem `Workspace` class):

- `Sifrious\\Molly\\Workspace\\ProjectIdentity`
- `Sifrious\\Molly\\Workspace\\WorkspaceIdentity`
- `Sifrious\\Molly\\Workspace\\RevisionIdentity`
- `Sifrious\\Molly\\Workspace\\WorkspaceReference`
- `Sifrious\\Molly\\Workspace\\Provenance`

Schemas use `molly.*` ids (not `stacks.*`) so Molly can diverge intentionally.

## Persistence target (MME-5343)

Add columns on `molly_tasks` / `molly_runs` (names may adjust in migration):

- `project_id` (uuid, nullable on legacy)
- `workspace_id` (uuid, nullable on legacy)
- `repository_id` / remote identity fields
- `checkout_id` / `checkout_kind`
- `base_sha` / `revision`
- `bloom_workspace_id` (nullable)
- `workspace` remains **optional observed path** (nullable after migration)

Legacy rows: classified as path-only; never fabricate stable IDs from paths. New creates fail closed without resolvable identity.

## Explicit non-copy

Do not import `Sifrious\\StacksContract` or `dep-stacks` at runtime. Attribution for extracted *ideas* lives in `IDENTITY-INTERNALIZATION.md`.
