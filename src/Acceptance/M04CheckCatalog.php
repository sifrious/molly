<?php

namespace Sifrious\Molly\Acceptance;

/**
 * The M04 checks molly:verify can run. Screen Recording and Accessibility are
 * required only where the remaining proof is the visible Bloom UI.
 */
final class M04CheckCatalog
{
    /** @return list<VerificationCheckDefinition> */
    public function checks(): array
    {
        $screen = [PermissionKind::ScreenRecording];
        $accessibility = [PermissionKind::Accessibility];

        return [
            $this->check('M04.1', 'Compiled host launches with plugin discovered through extension seam', []),
            $this->check('M04.2', 'New project', $accessibility),
            $this->check('M04.3', 'Add existing project', $accessibility),
            $this->check('M04.4', 'Tasks surface shows and creates tasks', []),
            $this->check('M04.5', 'Runs surface', []),
            $this->check('M04.6', 'Conversations surface', []),
            $this->check('M04.7', 'Graph surface', []),
            $this->check('M04.8', 'Glossary surface', []),
            $this->check('M04.9', 'Settings match effective run configuration', []),
            $this->check('M04.10', 'Worker controls/status', []),
            $this->check('M04.11', 'Traceability task to run to conversation to diff to receipt', []),
            $this->check('M04.12', 'CLI changes visible in Bloom', $screen),
            $this->check('M04.13', 'Incompatible plugin apiVersion produces useful error', []),
            $this->check('M04.14', 'Missing host handled', []),
            $this->check('M04.15', 'Broken assets/routes detected', []),
            $this->check('M04.16', 'State survives reload and host restart', []),
        ];
    }

    public function find(string $id): ?VerificationCheckDefinition
    {
        foreach ($this->checks() as $check) {
            if ($check->id === $id) {
                return $check;
            }
        }

        return null;
    }

    /** @param  list<PermissionKind>  $permissions */
    private function check(string $id, string $requirement, array $permissions): VerificationCheckDefinition
    {
        return new VerificationCheckDefinition($id, 'M04', 'bloom', $requirement, $permissions);
    }
}
