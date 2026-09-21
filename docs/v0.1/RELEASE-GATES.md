# Release gates (MME-5399)

Mac-local preferred evidence: `./bin/molly-release-gates`.

## Composer lock

- `composer.json` keeps compatible ranges for consumers.
- `composer.lock` is committed for maintainer/CI reproducibility.
- The distributed package archive **excludes** `composer.lock` (see `composer.json` `archive.exclude`).

## Commands

1. `composer validate --strict` (lock consistency required — do not use `--no-check-lock`)
2. `composer install` (locked known-good)
3. `composer audit --locked` — **fail** on vulnerable or abandoned packages unless an ADR documents an accepted exception with expiry
4. Lowest lane (optional matrix): `composer update --prefer-lowest --prefer-stable` then tests; restore lock after
5. Highest lane: `composer update` then tests; commit reviewed lock
6. Package Pest suite
7. Fresh Laravel 12 and 13 consumers installing a **tagged** Molly constraint (Packagist when listed; otherwise public VCS tag — never `dev-main` for release proof)

## PHP matrix

Herd Studio may only have one PHP line; run 8.3–8.5 via CI Actions (SHA-pinned) or additional local PHP builds when available. Push-to-main remains the portfolio CI gate; Actions red/billing is not a product blocker.
