<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Support\Facades\Validator;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
use Sifrious\Molly\Agents\AcceptanceWriter;
use Sifrious\Molly\Agents\AmpResponse;
use Sifrious\Molly\Agents\LocalOllama;
use Sifrious\Molly\Models\Task;
use Sifrious\Molly\Workspace;

/**
 * Derive numbered acceptance criteria from a plain-English story with the
 * configured model, then save a test-authoring task that carries them.
 * Molly stops there: a human runs the authoring task, approves the lock,
 * and starts the implementation.
 */
class CreateTaskFromStory
{
    public function __construct(private CreateTask $create, private AmpResponse $amp) {}

    /** @param  list<string>  $paths */
    public function handle(string $story, string $workspace, array $paths, string $testPath, ?string $nickname = null): Task
    {
        if (trim($story) === '' || strlen($story) > 4000 || ! mb_check_encoding($story, 'UTF-8')) {
            throw new RuntimeException('STORY_INVALID: Describe the story in 1 to 4000 UTF-8 bytes.');
        }
        (new Workspace($workspace))->taskPaths($paths, $testPath, true);
        if ($nickname !== null && trim($nickname) !== '') {
            Task::validateNickname($nickname);
        }

        $agent = new AcceptanceWriter;
        $input = json_encode(['story' => $story], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $criteria = $this->criteria($this->acquire($agent, $input));
        $numbered = implode("\n", array_map(fn (string $criterion, int $index): string => ($index + 1).'. '.$criterion, $criteria, array_keys($criteria)));

        $prompt = 'Write the Pest acceptance test at '.$testPath." for the story below. Cover every numbered acceptance criterion with at least one Pest test that names its number. Assert against the application's routes, pages, and components that the implementation will add, so the test fails until the behavior exists.\n\n"
            ."Story:\n".$story."\n\nAcceptance criteria:\n".$numbered;

        return $this->create->handle($prompt, $workspace, $paths, $testPath, [
            'provider' => 'molly-story',
            'story' => $story,
            'acceptance' => [
                'criteria' => $criteria,
                'text' => $numbered,
                'provenance' => [
                    'agent' => config('molly.agent', 'ollama'),
                    'model' => config('molly.agent', 'ollama') === 'ollama' ? config('molly.model') : null,
                    'prompt_digest' => hash('sha256', json_encode(['instructions' => $agent->instructions(), 'input' => $input], JSON_THROW_ON_ERROR)),
                    'story_digest' => hash('sha256', $story),
                    'derived_at' => now()->toIso8601String(),
                ],
            ],
        ], nickname: $nickname, allowTestEdits: true);
    }

    /** @return array<string, mixed> */
    private function acquire(AcceptanceWriter $agent, string $input): array
    {
        if (config('molly.agent', 'ollama') === 'amp') {
            return $this->amp->prompt($agent, $input);
        }

        if (config('molly.agent', 'ollama') === 'ollama') {
            LocalOllama::validate();
            $response = $agent->prompt($input, provider: 'ollama', model: config('molly.model'), timeout: config('molly.timeout'));

            return $response instanceof StructuredAgentResponse ? $response->toArray() : [];
        }

        throw new RuntimeException('AGENT_INVALID: Choose amp or ollama for molly.agent.');
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    private function criteria(array $result): array
    {
        $validator = Validator::make($result, [
            'criteria' => ['required', 'array', 'list', 'min:1', 'max:30'],
            'criteria.*' => ['required', 'string', 'max:500'],
        ]);
        if ($validator->fails()) {
            throw new RuntimeException('ACCEPTANCE_INVALID: The model did not return 1 to 30 acceptance criteria of up to 500 characters.');
        }

        return $result['criteria'];
    }
}
