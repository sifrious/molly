<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Acceptance\VerificationMode;
use Sifrious\Molly\Actions\VerifyMolly;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class MollyVerifyCommand extends Command
{
    use ReportsFailures;

    protected $signature = 'molly:verify
        {--permissionless : Run without requiring Screen Recording or Accessibility}
        {--check-permissions : Report those permissions and do not run acceptance checks}
        {--retry-native-ui : Rerun checks blocked only by a verifier permission}
        {--candidate= : Candidate commit SHA. Defaults to HEAD}
        {--evidence= : Directory for verification evidence}
        {--json : Print JSON only}';

    protected $description = 'Verify the M04 Bloom checks, and keep a missing macOS permission separate from a Molly failure';

    public function handle(VerifyMolly $verify): int
    {
        $selected = array_values(array_filter([
            $this->option('permissionless') ? VerificationMode::Permissionless : null,
            $this->option('check-permissions') ? VerificationMode::CheckPermissions : null,
            $this->option('retry-native-ui') ? VerificationMode::RetryNativeUi : null,
        ]));
        $evidence = $this->option('evidence');
        $evidence = is_string($evidence) && $evidence !== '' ? $evidence : storage_path('molly/acceptance');
        $candidate = $this->option('candidate');
        $candidate = is_string($candidate) && $candidate !== '' ? $candidate : null;

        if (count($selected) > 1) {
            $document = [
                'schema' => 'molly.acceptance-verification/1',
                'status' => 'invalid',
                'exit_code' => 4,
                'reason_code' => 'VERIFICATION_MODE_CONFLICT',
                'message' => 'Use only one of --permissionless, --check-permissions, and --retry-native-ui.',
                'release_complete' => false,
                'permission_granted_by_cli' => false,
            ];
        } else {
            $document = $verify->verify($selected[0] ?? VerificationMode::Default, $evidence, $candidate);
        }

        return $this->present($document);
    }

    /** @param  array<string, mixed>  $document */
    private function present(array $document): int
    {
        $exit = (int) ($document['exit_code'] ?? 2);
        if ($this->option('json')) {
            $this->writeJson($document, JSON_PRETTY_PRINT);
        } else {
            $this->render($document);
        }
        if ($exit !== self::SUCCESS) {
            $console = $this->output->getOutput();
            if ($console instanceof ConsoleOutputInterface) {
                $code = is_string($document['reason_code'] ?? null) ? $document['reason_code'] : match ($exit) {
                    1 => 'PRODUCT_FAIL',
                    2 => 'HARNESS_FAIL',
                    3 => 'VERIFICATION_INCOMPLETE',
                    default => 'VERIFICATION_INVALID',
                };
                $console->getErrorOutput()->writeln($code.': '.(string) ($document['message'] ?? 'Verification did not complete.'), OutputInterface::OUTPUT_RAW);
            }
        }

        return $exit;
    }

    /** @param  array<string, mixed>  $document */
    private function render(array $document): void
    {
        intro('Molly verification');
        if (($document['status'] ?? null) === 'invalid') {
            warning((string) $document['message']);

            return;
        }
        $preflight = is_array($document['preflight'] ?? null) ? $document['preflight'] : $document;
        if (is_string($preflight['message'] ?? null)) {
            note($preflight['message']);
        }
        if (is_array($document['checks'] ?? null) && $document['checks'] !== []) {
            table(['Check', 'Outcome', 'Reason'], array_map(fn (array $check): array => [
                (string) $check['check_id'],
                (string) $check['outcome'],
                (string) $check['reason_code'],
            ], $document['checks']));
        }
        outro((string) ($document['message'] ?? 'Verification finished.'));
    }
}
