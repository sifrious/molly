---
layout: default
title: Documentation publishing plan
---

# Documentation publishing plan

> This page is for maintainers. It describes a planned documentation deployment, not a step Molly users need for installation or task execution.

The planned public documentation hostname is `molly.mary.win`, using GitHub Pages from the repository's `main` branch and `/docs` directory.

## Planned setup

| Setting | Planned value |
| --- | --- |
| Repository | `sifrious/molly` |
| Source | `main` and `/docs` |
| Default Pages URL | `https://sifrious.github.io/molly/` |
| Custom hostname | `molly.mary.win` |

## Before enabling Pages

Maintainers should first verify:

- the docs build passes
- internal links resolve
- Getting started works in a clean Laravel application
- code examples are readable on a narrow screen
- planned and current behavior are clearly separated

## Enable the default Pages site

In GitHub repository settings, choose Pages, then deploy from `main` and `/docs`.

Before a custom domain is active, the site configuration should use:

```yaml
url: https://sifrious.github.io
baseurl: /molly
```

Confirm the default site loads before changing DNS.

## Add the custom hostname

Configure `molly.mary.win` as the repository's Pages custom domain. Follow GitHub's domain verification instructions for the account that owns the Pages site.

Then add the DNS record:

| Type | Host | Target |
| --- | --- | --- |
| CNAME | `molly` | `sifrious.github.io` |

With the custom domain active, the site configuration should use:

```yaml
url: https://molly.mary.win
baseurl: ""
```

## Verify the published site

Check HTTPS and the main routes:

```bash
curl -I https://molly.mary.win/
curl -I https://molly.mary.win/getting-started.html
curl -I https://molly.mary.win/assets/docs.css
```

Also test navigation, code blocks, keyboard access, and the 404 page.

## Rollback

A documentation content rollback is a normal Git revert followed by a successful Pages build.

If the custom hostname must be retired, remove its routing record and restore the default Pages configuration in a coordinated change.
