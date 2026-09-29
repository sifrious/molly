<?php

namespace Sifrious\Molly\Verification;

use RuntimeException;

/**
 * Guards test-authoring output: Pest-discoverable Feature tests that target the app,
 * without inlining product components, routes, or schema.
 */
class PestTestAuthoring
{
    /**
     * @throws RuntimeException
     */
    public function assertAcceptable(string $content): void
    {
        $issues = $this->issues($content);
        if ($issues === []) {
            return;
        }

        throw new RuntimeException(
            'TEST_AUTHORING_INVALID: '.implode(' ', $issues)
        );
    }

    /**
     * @return list<string>
     */
    public function issues(string $content): array
    {
        $issues = [];

        if (! $this->opensAsPhp($content)) {
            $issues[] = 'Start the file with <?php; Pest found no tests in a file without the opening tag.';
        }

        if (! $this->hasPestDiscovery($content)) {
            $issues[] = 'Emit Pest it() or test() Feature cases Pest can discover; do not rely on PHPUnit @test methods alone.';
        }

        if ($this->embedsSchema($content)) {
            $issues[] = 'Do not call Schema builders inside the acceptance Pest file.';
        }

        if ($this->registersLivewire($content)) {
            $issues[] = 'Do not register Livewire components inside the acceptance Pest file; assert against the application\'s own component classes instead.';
        }

        if ($this->registersRoutes($content)) {
            $issues[] = 'Do not define application routes inside the acceptance Pest file; hit app routes from the test instead.';
        }

        if ($this->definesAnonymousComponent($content)) {
            $issues[] = 'Do not embed anonymous Livewire components in the acceptance Pest file.';
        }

        $vacuous = $this->vacuousSeeAssertions($content);
        if ($vacuous !== []) {
            $issues[] = 'Assert specific rendered text, not a single character: '.implode(', ', $vacuous).(count($vacuous) === 1 ? ' passes or fails on almost any page, so it proves' : ' pass or fail on almost any page, so they prove').' nothing.';
        }

        return $issues;
    }

    private function opensAsPhp(string $content): bool
    {
        return (bool) preg_match('/\A(?:\xEF\xBB\xBF)?\s*<\?php\b/', $content);
    }

    private function hasPestDiscovery(string $content): bool
    {
        return (bool) preg_match('/\bit\s*\(/', $content)
            || (bool) preg_match('/\btest\s*\(/', $content);
    }

    private function embedsSchema(string $content): bool
    {
        return (bool) preg_match('/\bSchema\s*::\s*(create|table|drop|dropIfExists)\s*\(/i', $content);
    }

    private function registersLivewire(string $content): bool
    {
        return (bool) preg_match('/\bLivewire\s*::\s*component\s*\(/', $content);
    }

    private function registersRoutes(string $content): bool
    {
        return (bool) preg_match('/\bRoute\s*::\s*(get|post|put|patch|delete|any|match|view|redirect|middleware|group)\s*\(/', $content);
    }

    /**
     * assertSee(), assertSeeText(), assertDontSee(), or assertDontSeeText() calls whose
     * expected text is empty or one character, such as assertSee('1') or assertDontSee(0).
     *
     * @return list<string>
     */
    private function vacuousSeeAssertions(string $content): array
    {
        preg_match_all('/\bassert(?:Dont)?See(?:Text)?\s*\(\s*(?:\'[^\'\\\\]?\'|"[^"\\\\$]?"|-?\d)\s*[,)]/', $content, $matches);

        return array_values(array_unique(array_map(fn (string $call): string => rtrim(preg_replace('/\s+/', '', $call), ',').(str_ends_with(rtrim($call), ')') ? '' : ')'), $matches[0])));
    }

    private function definesAnonymousComponent(string $content): bool
    {
        if (! preg_match('/new\s+class\b/', $content)) {
            return false;
        }

        return str_contains($content, 'Livewire\\Component')
            || str_contains($content, 'Livewire\Component')
            || (bool) preg_match('/extends\s+Component\b/', $content);
    }
}
