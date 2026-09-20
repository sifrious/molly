<?php

namespace Sifrious\Molly\Verification;

/**
 * Turns truncated Pest failure text into short, actionable retry hints for the change writer.
 */
class PestAssertionHints
{
    /**
     * @return list<array{pattern: string, hint: string}>
     */
    public function handle(?string $output): array
    {
        if (! is_string($output) || trim($output) === '') {
            return [];
        }

        $haystack = $this->normalize($output);
        $hints = [];

        if ($this->looksLikeForbiddenMismatch($haystack)) {
            $hints[] = [
                'pattern' => 'livewire_assert_forbidden',
                'hint' => 'A Pest/Livewire assertForbidden() (or Expected 403, got 200) on a component action means the action itself must deny the caller with HTTP 403 via abort(403), abort_unless(...), or authorize — not a silent no-op, and not by returning 403 from the whole page GET.',
            ];
        }

        return $hints;
    }

    private function normalize(string $output): string
    {
        $decoded = json_decode($output, true);
        if (is_array($decoded) && ($decoded['tool'] ?? null) === 'pest') {
            $parts = [];
            foreach ([...($decoded['failures'] ?? []), ...($decoded['error_details'] ?? [])] as $row) {
                if (is_array($row)) {
                    $parts[] = (string) ($row['test'] ?? '');
                    $parts[] = (string) ($row['message'] ?? '');
                }
            }

            return strtolower(implode("\n", $parts));
        }

        return strtolower($output);
    }

    private function looksLikeForbiddenMismatch(string $haystack): bool
    {
        if (str_contains($haystack, 'assertforbidden')) {
            return true;
        }

        $expects403 = str_contains($haystack, 'expected response status code [403]')
            || str_contains($haystack, 'expected status code 403')
            || (str_contains($haystack, '403') && str_contains($haystack, 'forbidden'));

        $got200 = str_contains($haystack, 'received 200')
            || str_contains($haystack, 'got 200')
            || str_contains($haystack, 'identical to 403');

        return $expects403 && $got200;
    }
}
