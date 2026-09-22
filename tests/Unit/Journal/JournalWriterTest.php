<?php

use Sifrious\Molly\Journal\JournalWriter;

it('creates a private 0700 directory and validates it', function () {
    $root = realpath(sys_get_temp_dir()).'/molly-journal-writer-'.uniqid('', true);
    $dir = $root.'/.molly';
    $writer = new JournalWriter;
    $writer->ensureDirectory($root);
    $writer->ensureDirectory($dir);

    expect(is_dir($dir))->toBeTrue()
        ->and(is_link($dir))->toBeFalse()
        ->and(decoct(fileperms($dir) & 0777))->toBe('700');
});

it('rejects symlinked directories', function () {
    $root = realpath(sys_get_temp_dir()).'/molly-journal-link-'.uniqid('', true);
    mkdir($root, 0700, true);
    $real = $root.'/real';
    $link = $root.'/link';
    mkdir($real, 0700);
    symlink($real, $link);

    expect(fn () => (new JournalWriter)->validateDirectory($link))
        ->toThrow(RuntimeException::class, 'JOURNAL_PATH_INVALID:');
});

it('rejects symlink or hard-linked file destinations', function () {
    $root = realpath(sys_get_temp_dir()).'/molly-journal-file-'.uniqid('', true);
    mkdir($root, 0700, true);
    $file = $root.'/journal.md';
    file_put_contents($file, 'x');
    $link = $root.'/journal-link.md';
    symlink($file, $link);

    expect(fn () => (new JournalWriter)->validateFile($link))
        ->toThrow(RuntimeException::class, 'JOURNAL_PATH_INVALID:');
});

it('atomically replaces a journal file with compare-and-swap', function () {
    $root = realpath(sys_get_temp_dir()).'/molly-journal-cas-'.uniqid('', true);
    $path = $root.'/JOURNAL.md';
    $writer = new JournalWriter;
    $writer->ensureDirectory($root);
    $writer->replaceFile($path, "one\n", false);
    expect(file_get_contents($path))->toBe("one\n")
        ->and(decoct(fileperms($path) & 0777))->toBe('600');

    $hash = hash_file('sha256', $path);
    $writer->replaceFile($path, "two\n", $hash);
    expect(file_get_contents($path))->toBe("two\n");
});

it('fails compare-and-swap when the expected hash does not match', function () {
    $root = realpath(sys_get_temp_dir()).'/molly-journal-cas-fail-'.uniqid('', true);
    $path = $root.'/JOURNAL.md';
    $writer = new JournalWriter;
    $writer->ensureDirectory($root);
    $writer->replaceFile($path, "one\n", false);

    expect(fn () => $writer->replaceFile($path, "two\n", 'deadbeef'))
        ->toThrow(RuntimeException::class, 'JOURNAL_WRITE_FAILED:');
});

it('prepares .molly and appends ignore star without overwriting user lines', function () {
    $root = realpath(sys_get_temp_dir()).'/molly-journal-support-'.uniqid('', true);
    $writer = new JournalWriter;
    $writer->ensureDirectory($root);
    $writer->prepareMollyDirectory($root);

    $gitignore = $root.'/.molly/.gitignore';
    expect(is_dir($root.'/.molly'))->toBeTrue()
        ->and(file_get_contents($gitignore))->toBe("*\n");

    file_put_contents($gitignore, "notes.md\n");
    $writer->prepareMollyDirectory($root);
    expect(file_get_contents($gitignore))->toBe("notes.md\n*\n");

    $before = file_get_contents($gitignore);
    $writer->prepareMollyDirectory($root);
    expect(file_get_contents($gitignore))->toBe($before);
});
