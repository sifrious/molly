---
layout: default
title: Getting started
---

# Get started with Molly

Molly works on its own. Bloom is an optional workspace and review UI around it. Pick one path.

| Path | Choose it when | Start here |
| --- | --- | --- |
| **Molly standalone** | You want a terminal-first Laravel workflow. No Bloom required. | [QuickStart: Molly standalone](quickstart-standalone.md) |
| **Molly + Bloom** | You already use Bloom and want it to own the workspace, diff, and pull request UI while Molly owns verification and evidence. | [QuickStart: Molly + Bloom](quickstart-bloom.md) |

If you are not sure, start standalone. The Bloom path builds on it.

## No Laravel app yet?

The demo installer creates a fresh Laravel app, installs Pest and the current tagged Molly release, and scaffolds the greeting demo task:

```bash
curl -fsSL https://raw.githubusercontent.com/sifrious/molly/v0.1.3/bin/molly-demo -o molly-demo
bash molly-demo ~/molly-demo
```

It writes only under the chosen directory and refuses a non-empty path unless you pass `--force`. It does not install Bloom or call a hosted AI service. When it finishes, continue with the [standalone QuickStart](quickstart-standalone.md#copy-paste-first-run) from the `molly:setup` step.

## Before either path

- [Compatibility](compatibility.md) lists tested PHP, Laravel, and Pest versions.
- [Local Ollama details](ollama-quickstart.md) covers models, endpoints, and doctor codes.
- [Troubleshooting](troubleshooting.md) covers doctor failures and the sandbox check.
