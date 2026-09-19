<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Actions\ShowTask;
use Sifrious\Molly\Models\Task;
use Sifrious\Molly\Workspace;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;

class MollyDemoCommand extends Command
{
    public const TASK_NAME = 'demo-greeting';

    public const PROMPT = 'Return Hello from the greeting helper.';

    public const IMPLEMENTATION_PATH = 'app/Greeting.php';

    public const TEST_PATH = 'tests/Feature/GreetingTest.php';

    protected $signature = 'molly:demo {--workspace= : Host Laravel app root} {--json : Print JSON only}';

    protected $description = 'Scaffold the first greeting demo task for Terminal and Bloom';

    public function handle(CreateTask $create, ShowTask $show): int
    {
        try {
            $workspace = (string) ($this->option('workspace') ?: base_path());
            $files = new Workspace($workspace);
            $root = $files->path;

            $this->ensureMollyGitignored($root);
            $createdGreeting = $this->ensureGreetingStub($root);
            $createdTest = $this->ensureGreetingTest($root);

            $existing = $show->handle(self::TASK_NAME);
            if ($existing instanceof Task) {
                $task = $existing;
                $createdTask = false;
            } else {
                $task = $create->handle(
                    self::PROMPT,
                    $root,
                    [self::IMPLEMENTATION_PATH],
                    self::TEST_PATH,
                    nickname: self::TASK_NAME,
                    allowTestEdits: false,
                );
                $createdTask = true;
            }

            $payload = [
                'status' => 'ready',
                'task' => $task->reference(),
                'task_id' => $task->id,
                'prompt' => self::PROMPT,
                'allowed_write_paths' => [self::IMPLEMENTATION_PATH],
                'protected_paths' => [self::TEST_PATH],
                'created' => [
                    'greeting' => $createdGreeting,
                    'test' => $createdTest,
                    'task' => $createdTask,
                ],
                'next' => [
                    'php artisan molly:doctor --workspace='.$root,
                    'php artisan molly:start '.self::TASK_NAME,
                    'In Bloom: Open existing branch… then run php artisan molly:bloom-contract '.self::TASK_NAME.' --workspace-id=UUID --branch=BRANCH --base-sha=40CHARSHA',
                ],
            ];

            if ($this->option('json')) {
                $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
            } else {
                note('Molly demo task "'.self::TASK_NAME.'" is ready.');
                note('Request: '.self::PROMPT);
                note('Molly may change: '.self::IMPLEMENTATION_PATH);
                note('Protected Pest test: '.self::TEST_PATH);
                $this->newLine();
                $this->line('Next steps (plain language):');
                $this->line('1. Check the workspace: php artisan molly:doctor');
                $this->line('2. Start the demo in Terminal: php artisan molly:start '.self::TASK_NAME);
                $this->line('3. Or open an existing Bloom branch, then print the contract (Bloom supplies the three values):');
                $this->line('   php artisan molly:bloom-contract '.self::TASK_NAME.' --workspace-id=… --branch=… --base-sha=…');
                $this->line('Molly never creates a Bloom worktree and never calls cloud from this command.');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($this->option('json')) {
                $this->line(json_encode(['status' => 'error', 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
            } else {
                error($exception->getMessage());
            }

            return self::FAILURE;
        }
    }

    private function ensureMollyGitignored(string $root): void
    {
        $gitignore = $root.'/.gitignore';
        $needle = '.molly/';
        if (! is_file($gitignore)) {
            File::put($gitignore, $needle.PHP_EOL);

            return;
        }

        $contents = File::get($gitignore);
        if (preg_match('/(^|\\n)\\s*\\.molly\\/?\\s*($|\\n)/', $contents) === 1) {
            return;
        }

        File::append($gitignore, (str_ends_with($contents, "\n") ? '' : "\n").$needle."\n");
    }

    private function ensureGreetingStub(string $root): bool
    {
        $path = $root.'/'.self::IMPLEMENTATION_PATH;
        if (is_file($path)) {
            return false;
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, <<<'PHP'
<?php

namespace App;

class Greeting
{
    public static function message(): string
    {
        // Molly fills this in so the protected Pest test can pass.
        return '';
    }
}

PHP);

        return true;
    }

    private function ensureGreetingTest(string $root): bool
    {
        $path = $root.'/'.self::TEST_PATH;
        if (is_file($path)) {
            return false;
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, <<<'PHP'
<?php

use App\Greeting;

it('returns Hello from the greeting helper', function () {
    expect(Greeting::message())->toBe('Hello');
});

PHP);

        return true;
    }
}
