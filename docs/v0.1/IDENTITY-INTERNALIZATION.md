# Identity internalization & drift policy (MME-5352)

## Source revision

Behavioral shape for Molly-owned workspace references was characterized against:

- `stacks-contract` checkout `stacks-contract-mme-4935` @ `270358572d9c5d062c229ee4d0adf41c87e5ead6`  
  (`WorkspaceReference` schema `stacks.workspace-reference.v1`, `ExecutionProvenance` schema `stacks.execution-provenance.v1`)
- Domain ID vocabulary referenced from `dep-stacks` @ `15916a7f12d01e883144ef6ccb8d4a12815b0a49` (read-only)

Molly does **not** Composer-require those packages. Copied/adapted ideas are re-homed under `Sifrious\\Molly\\Workspace\\*` with `molly.*` schema ids.

## Attribution

Stacks Contract sources are MIT (see that checkout's README/composer). Molly remains MIT. This note records the extraction point for license/attribution diligence; Molly owns the resulting code.

## Drift policy

- Molly internalized a **defined** surface at the SHAs above.
- Molly owns the implementation afterward and may diverge for the public OSS boundary.
- Future Stacks changes are **not** auto-adopted.
- Any re-sync must be intentional, reviewed, and backed by Molly characterization tests.
- No runtime private-repo check; no silent lockstep with Stacks.

## Intentional deviations

- Schema namespace: `molly.workspace-reference.v1` / `molly.provenance.v1` (not `stacks.*`).
- Filesystem helper class `Sifrious\\Molly\\Workspace` remains path/lease utility; identity types live in the `Workspace\\` namespace.
- Persistence binds tasks/runs to Molly identities (MME-5343); absolute paths are metadata only.
