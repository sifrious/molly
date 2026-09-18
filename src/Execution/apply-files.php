<?php

$editsPath = $argv[1] ?? '';
$workspace = $argv[2] ?? '';
if ($editsPath === '' || $workspace === '' || ! is_file($editsPath) || ! is_dir($workspace)) {
    fwrite(STDERR, "SANDBOX_APPLY_INVALID\n");
    exit(2);
}

$edits = json_decode((string) file_get_contents($editsPath), true);
if (! is_array($edits) || ! array_is_list($edits)) {
    fwrite(STDERR, "SANDBOX_APPLY_INVALID\n");
    exit(2);
}

foreach ($edits as $edit) {
    if (! is_array($edit) || ! is_string($edit['path'] ?? null) || ! is_string($edit['content'] ?? null)) {
        fwrite(STDERR, "SANDBOX_APPLY_INVALID\n");
        exit(2);
    }
    $absolute = $workspace.'/'.$edit['path'];
    if (file_put_contents($absolute, $edit['content']) === false || file_get_contents($absolute) !== $edit['content']) {
        fwrite(STDERR, "SANDBOX_APPLY_FAILED\n");
        exit(2);
    }
}
