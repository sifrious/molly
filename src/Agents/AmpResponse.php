<?php

namespace Sifrious\Molly\Agents;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use JsonException;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use RuntimeException;
use Throwable;

class AmpResponse
{
    /** @return array<string, mixed> */
    public function prompt(Agent&HasStructuredOutput $agent, string $input): array
    {
        $timeout = config('molly.timeout');
        if (! is_int($timeout) || $timeout < 1 || $timeout > 3600) {
            throw new RuntimeException('AMP_TIMEOUT_INVALID: Set molly.timeout to an integer from 1 to 3600 seconds.');
        }
        $directory = sys_get_temp_dir().'/molly-amp-'.bin2hex(random_bytes(16));
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('AMP_TEMP_FAILED: Molly could not create an isolated Amp directory.');
        }
        try {
            $settings = $directory.'/settings.json';
            touch($settings);
            chmod($settings, 0600);
            file_put_contents($settings, json_encode([
                'amp.tools.enable' => ['__molly_no_tools__'],
                'amp.tools.disable' => ['*'],
                'amp.mcpServers' => new \stdClass,
                'amp.mcpPermissions' => [
                    ['matches' => ['command' => '*'], 'action' => 'reject'],
                    ['matches' => ['url' => '*'], 'action' => 'reject'],
                ],
                'amp.updates.mode' => 'disabled',
                'amp.remoteThreadCreation.enabled' => false,
                'amp.skills.disableClaudeCodeSkills' => true,
            ], JSON_THROW_ON_ERROR));
            $schema = new JsonSchemaTypeFactory;
            $prompt = $agent->instructions()."\n\nReturn one JSON object matching this schema:\n"
                .json_encode($schema->object($agent->schema($schema))->toArray(), JSON_THROW_ON_ERROR)
                ."\n\nTask data:\n".$input;
            $result = Process::path($directory)->timeout($timeout)->input($prompt)->run([
                'amp', '--settings-file', $settings, '--log-file', $directory.'/amp.log',
                '--no-ide', '--no-remote-control-terminal', '--visibility', 'private', '--stream-json', '-x',
            ]);
            if (! $result->successful()) {
                throw new RuntimeException('AMP_FAILED: Amp did not finish successfully. Check the Amp CLI login and account access.');
            }

            return $this->parse($result->output());
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException && str_starts_with($exception->getMessage(), 'AMP_')) {
                throw $exception;
            }
            throw new RuntimeException('AMP_FAILED: Amp could not return a complete response before the deadline.', 0, $exception);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    /** @return array<string, mixed> */
    private function parse(string $output): array
    {
        if (strlen($output) > 2 * 1024 * 1024) {
            throw new RuntimeException('AMP_OUTPUT_INVALID: Amp returned too much output.');
        }
        $initialized = false;
        $terminal = null;
        $text = '';
        try {
            foreach (explode("\n", trim($output)) as $line) {
                $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($event) || $terminal !== null) {
                    throw new RuntimeException('AMP_OUTPUT_INVALID: Amp returned an invalid event sequence.');
                }
                if (($event['type'] ?? null) === 'system' && ($event['subtype'] ?? null) === 'init') {
                    if ($initialized || ($event['tools'] ?? null) !== []) {
                        throw new RuntimeException('AMP_TOOLS_ENABLED: Amp must report an empty tool list.');
                    }
                    $initialized = true;
                } elseif (($event['type'] ?? null) === 'assistant') {
                    $text = '';
                    foreach ($event['message']['content'] ?? [] as $content) {
                        if (($content['type'] ?? null) === 'tool_use') {
                            throw new RuntimeException('AMP_TOOLS_ENABLED: Amp attempted a tool call.');
                        }
                        if (($content['type'] ?? null) === 'text') {
                            $text .= $content['text'] ?? '';
                        }
                    }
                } elseif (($event['type'] ?? null) === 'result') {
                    $terminal = $event;
                }
            }
            if (! $initialized || ($terminal['subtype'] ?? null) !== 'success' || ($terminal['is_error'] ?? null) !== false) {
                throw new RuntimeException('AMP_INCOMPLETE: Amp did not report a successful terminal result.');
            }
            $data = json_decode($terminal['result'] ?? $text, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($data) || array_is_list($data)) {
                throw new RuntimeException('AMP_OUTPUT_INVALID: Amp must return a JSON object.');
            }

            return $data;
        } catch (JsonException) {
            throw new RuntimeException('AMP_OUTPUT_INVALID: Amp returned invalid JSON.');
        }
    }
}
