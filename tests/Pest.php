<?php

use Illuminate\Support\Facades\File;
use Sifrious\Molly\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

function writeProtectedTest(string $workspace, string $path = 'tests/GreetingTest.php', string $contents = '<?php it("exists", fn () => expect(true)->toBeTrue());'): string
{
    File::ensureDirectoryExists($workspace.'/'.dirname($path));
    File::put($workspace.'/'.$path, $contents);

    return hash('sha256', $contents);
}
