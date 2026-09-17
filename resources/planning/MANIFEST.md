# Bundled planning sources

`guide.json` defines five ordered planning questions and links each question to local source files. Every source has a URL, revision, topic list, and SHA-256 digest of the bundled file bytes. The graph contains selected passages and short summaries. The graph does not contain complete manuals and needs no network access during planning.

## Provenance and permissions

- Laravel 13 passages come from `laravel/docs` revision `b94b890362111c44de223e09502c610a9d9f20d8`. The selected section headings appear in each file. `LARAVEL-LICENSE.md` reproduces the original MIT license and Taylor Otwell copyright notice from that revision. Source chapters remain the authority for examples and surrounding constraints.
- Mary Perry's public talk notes come from `https://clever.mary.win/`, linked by `https://mary.win/`. The author authorized reuse in Molly. The bundle preserves selected wording from sections 03, 10, and 11. No public general license was found, so the bundle does not assign one. The revision is the SHA-256 of the selected public section objects serialized as UTF-8 JSON with sorted keys, no extra whitespace, and literal Unicode. The separate `sha256` field hashes the bundled Markdown file. The original Out of the Tar Pit paper and other cited works are not reproduced.
- NativePHP Desktop v2 and Mobile v4 files contain original short summaries of the official introductions at `NativePHP/nativephp.com` revision `0c1da20fc3cc98accef49dab372a57cbf60ad872`. No NativePHP documentation passages, implementation code, or license grant are copied. Each file distinguishes the summary from Molly's planning guidance. The files do not establish compatibility with a host application's installed packages.

The local `mary.win` repository at revision `025cb99218e339370b0497f080c40feb7e59ca65` contains a site skeleton rather than these public talk notes. The bundled Mary content therefore comes from the public page, not application records or private files. Transient page data, subscription forms, and credentials are excluded.

## Editorial limits

The questions and planning guidance are Molly's interpretation of the linked sources. Source citations explain the reasoning; source authors have not endorsed Molly's questions or review decisions. A matching topic suggests material to consult and does not prove that a package, event, queue, or interface belongs in the solution.

Laravel excerpts preserve upstream wording and code. Relative links inside excerpts refer to the upstream chapter and are not additional bundled files. NativePHP tracks must remain separate. Check the host application's installed versions before using a documented API. The bundle is a dated snapshot, not a claim that every linked page or release remains current.

## Updating the bundle

Fetch source material only when maintaining the package. Review permission and version changes, select the needed passages, and update source revisions and file digests together. Keep the guide, sources, and attribution files below 128 KiB uncompressed. Validate every source reference, graph edge, local path, and digest before shipping. Planning must not fetch links at runtime.
