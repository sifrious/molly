# Publishing

> For maintainers. Nothing here is needed to use Molly.

The documentation is plain Markdown under `docs/`, read on GitHub. There is no site generator and no build step. `docs/index.html` is a static landing page that links into the Markdown pages; how and where it is hosted is a separate decision from the docs themselves.

## Checks

The Documentation workflow runs `bin/molly-docs-check` on every change to `README.md` or `docs/`. It follows every relative link to a file and every `#anchor` to a heading, using GitHub's heading slug rules. Run it locally before pushing:

```bash
python3 bin/molly-docs-check
```

`bin/molly-docs-walkthrough` regenerates the web interface screenshots under `docs/v0.1/walkthrough/` from a running application.

## Switching the public install line to v1

The install lines stay on the tagged `^0.1.1` constraint until all of these are true:

1. The `v1.0.0` tag exists on `main`.
2. Composer resolves `sifrious/molly:^1.0` from the documented source, Packagist or the public repository.
3. Fresh Laravel 12 and 13 applications install `^1.0`, complete the demo flow, and export a Bloom contract.

Then change these together in one commit:

- `InitializeMollyInExistingProject::RELEASE_CONSTRAINT` and the `MOLLY_CONSTRAINT` default in `bin/molly-demo` (a test keeps them equal)
- The install lines in `README.md`, `docs/index.html`, `docs/getting-started.md`, `docs/quickstart-standalone.md`, and `docs/troubleshooting.md`
- The tagged `bin/molly-demo` download URL in `README.md` and `docs/getting-started.md`
- The supported-versions table in `SECURITY.md`
- The repository line, once Packagist lists the package
