# Molly Bloom plugin

Manifest Bloom's generic plugin host discovers after install.

## Install path

Copy this folder to:

```text
~/Library/Application Support/Bloom/Plugins/sifrious.molly/
```

so the file `plugin.json` sits at:

```text
~/Library/Application Support/Bloom/Plugins/sifrious.molly/plugin.json
```

Enable with `enabled.json` listing `"sifrious.molly"`, or use `bin/molly-bloom-plugin-register`.

Bloom hosts nav + SwiftUI through its plugin registry. Molly owns domain state; this package only declares the surface.
