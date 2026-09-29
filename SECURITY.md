# Security policy

## Supported versions

| Version | Supported |
| --- | --- |
| 0.1.x (latest tag) | Yes |
| `main` | Yes, as the development branch for the next release |
| Anything older than the latest 0.1.x tag | No. Upgrade within `^0.1.1`. |

Security fixes land on `main` and ship in the next 0.1.x tag. Install tagged releases, not a development branch.

## Report a vulnerability

Please use GitHub's private vulnerability reporting for this repository. Do not open a public issue for a suspected vulnerability.

Include the affected command or component, the conditions needed to reproduce the problem, its likely impact, and a minimal reproduction when possible. Remove credentials, private source code, and personal information before submitting the report.

You should receive an acknowledgement within seven days. A confirmed report will be investigated before details are published.

## Security boundary

Molly is a development dependency for trusted local checkouts. It limits proposed edits to the files named by a task. When the host supports Landlock and Linux user and network namespaces, Molly runs the writer and the Pest verifier inside that sandbox, and `molly:doctor` reports whether it is available. Pest still executes project PHP code. Without the sandbox, or with the local `molly.sandbox.allow_unsafe` override, agent processes, Pest, and project code run with the local user's full permissions.

Approving a change, locking a Pest test, recording a pull request or merge, posting a GitHub comment, and handing off a task are human decisions. The MCP server refuses them with `HUMAN_APPROVAL_REQUIRED` and changes nothing; a person records them with the Artisan command and `--approve`. Molly cannot tell a person from an agent that has shell access to the same terminal, so do not give an agent a shell where it can run those commands for you.

Keep provider credentials in environment variables. Review generated code and tests before committing them, and do not enable the unauthenticated local web interface on a public host.
