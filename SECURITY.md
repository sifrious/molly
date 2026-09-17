# Security policy

## Supported versions

Molly does not have a tagged release yet. Security fixes are applied to the current `main` branch while the package remains on `dev-main`.

## Report a vulnerability

Please use GitHub's private vulnerability reporting for this repository. Do not open a public issue for a suspected vulnerability.

Include the affected command or component, the conditions needed to reproduce the problem, its likely impact, and a minimal reproduction when possible. Remove credentials, private source code, and personal information before submitting the report.

You should receive an acknowledgement within seven days. A confirmed report will be investigated before details are published.

## Security boundary

Molly is a development dependency for trusted local checkouts. It limits proposed edits to the files named by a task, but it is not a sandbox. Agent processes, Pest, and project code run with the local user's permissions.

Keep provider credentials in environment variables. Review generated code and tests before committing them, and do not enable the unauthenticated local web interface on a public host.
