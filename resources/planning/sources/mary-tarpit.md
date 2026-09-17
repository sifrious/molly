# Mary Perry: Cleverness is a loan

Source: https://clever.mary.win/

Revision: `sha256:afc4c4d05d4efca5ecdc05f54b7a35cded9b1c07677fda6e74e8cd33e56ed32d`. Retrieved 2026-09-17.

Selected public talk notes by Mary Perry, reproduced with the author's authorization for this package. No general upstream license was found. Original wording follows. These notes discuss Out of the Tar Pit; the original paper is not bundled.

## Complexity, divided

Following Brooks vis-à-vis Aristotle, complexity divides into essence — the difficulties inherent in the nature of software — and accidents, the difficulties that attend its production but are not inherent.

We can remove accidental complexity, or salvage it for performance or natural data representation; I call that pragmatic. We can only introduce complexity thoughtfully if we are aware of it.

Cleverness is its own type: any part of a program that cannot be, or has not been, simply expressed.

The one-liner you have to find someone to explain. The invisible mechanism that may or may not be slowing down queries on prod. Code where only the guy who wrote it has the courage to touch it.

In every sense of the word: a skill issue.

## The tar pit

At the core of the application is your essential complexity — your domain, the irreducible essence of the problem you're trying to solve.

Out of the Tar Pit's ideal world, borrowed for the purposes of discussion: ask users what they need and treat anything they mention as essential — a complete picture, no ambiguity or omissions; represent their requirements in executables; then build it.

If the user doesn't mention it, it's accidental. Computed values? Accidental. Things your users aren't likely to know about? Accidental. Operations are essential if the user describes them.

Then complexity comes back in on purpose — pragmatically: performance, without optimizing for issues we are not experiencing, premature optimization being the root of all evil; stored computed data, when the benefits outweigh the cost; and whatever the application needs to be technologically possible.

For web applications, that last one is a lot of complexity — which we can choose to outsource to Laravel.

## The layers

The essential domain sits at the center — the basic representation of user requirements — and each layer out is framework classes that stay borrowed seams so long as nothing welds them in.

Three major enabling points run through the whole map: container resolution and binding, registration, and configuration and values.
