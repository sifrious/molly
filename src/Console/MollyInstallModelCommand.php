<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Laravel\Prompts\Progress;
use Sifrious\Molly\Actions\InstallModel;
use Sifrious\Molly\ModelFit\InstallationHeadroom;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\spin;

class MollyInstallModelCommand extends Command
{
    use ReportsFailures;

    protected $signature = 'molly:install-model {model? : An approved model; defaults to the one molly:preflight selects} {--destination= : Directory that holds Ollama models (default: OLLAMA_MODELS or ~/.ollama/models)} {--approve : Authorize the download this command shows} {--json : Print JSON only}';

    protected $description = 'Install an approved Ollama model that fits this Mac, verify its digest, and check that it can do Molly\'s work';

    public function handle(InstallModel $install): int
    {
        $model = $this->argument('model');
        $destination = $this->option('destination');
        $model = is_string($model) && $model !== '' ? $model : null;
        $destination = is_string($destination) && $destination !== '' ? $destination : null;
        $json = (bool) $this->option('json');

        try {
            $plan = $json ? $install->plan($model, $destination) : spin(fn (): array => $install->plan($model, $destination), 'Measuring this Mac');
            if ($plan['status'] !== 'ready') {
                return $this->reportFailure($plan['code'].': '.$plan['message'], $this->document($plan));
            }

            $bytes = $plan['download']['bytes'];
            if ($bytes > 0 && ! $this->option('approve')) {
                if ($json || ! $this->input->isInteractive()) {
                    $prompt = $this->authorization($plan);
                    // Keep the model and every option as typed, with the models directory as the full path shown, so the approved run plans the same download.
                    $rerun = $this->commandLine(['model' => $plan['model'], 'destination' => $destination === null ? null : ($plan['download']['destination'] ?? $destination), 'approve' => true]);

                    return $this->reportFailure('DOWNLOAD_AUTHORIZATION_REQUIRED: '.$prompt.' Run: '.$rerun, ['status' => 'authorization_required', 'code' => 'DOWNLOAD_AUTHORIZATION_REQUIRED', 'message' => $prompt, 'rerun' => $rerun] + $this->document($plan));
                }
                note($this->downloadFacts($plan));
                if (! confirm('Download '.InstallationHeadroom::gigabytes($bytes).' for '.$plan['model'].'?', default: false, yes: 'Download', no: 'Cancel')) {
                    return $this->reportFailure('DOWNLOAD_NOT_AUTHORIZED: Molly downloaded nothing.', ['status' => 'refused', 'code' => 'DOWNLOAD_NOT_AUTHORIZED'] + $this->document($plan));
                }
            }

            $bar = null;
            $shown = 0;
            $progress = $json ? null : function (int $completed, int $total) use (&$bar, &$shown): void {
                if (! $bar instanceof Progress) {
                    $bar = progress('Downloading', $total);
                    $bar->start();
                }
                if ($completed > $shown) {
                    $bar->advance($completed - $shown);
                    $shown = $completed;
                }
            };
            $result = $install->install($plan['model'], $destination, $bytes, $progress);
            if ($bar instanceof Progress) {
                $bar->finish();
            }
        } catch (Throwable $exception) {
            return $this->reportException($exception, ['status' => 'failed']);
        }

        if ($result['status'] !== 'ready') {
            return $this->reportFailure($result['code'].': '.$result['message'], $result);
        }

        if ($json) {
            $this->writeJson($result);

            return self::SUCCESS;
        }

        foreach ($result['events'] as $event) {
            note($event['step'].': '.$event['detail']);
        }
        info($result['message']);
        note("Use it with:\n".implode("\n", $result['next']));

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $plan */
    private function authorization(array $plan): string
    {
        $download = $plan['download'];
        $volume = ($download['volume_name'] ?? null) !== null ? 'the '.$download['volume_name'].' volume' : 'the volume';

        return 'Download '.InstallationHeadroom::gigabytes($download['bytes']).' for '.$plan['model']
            .($download['model'] !== $plan['model'] ? ' (Ollama pulls '.$download['model'].' and derives '.$plan['model'].' from it)' : '')
            .' to '.($download['destination'] ?? 'the Ollama models directory').' on '.$volume.' mounted at '.($download['mount_point'] ?? 'an unknown mount point')
            .', which has '.($download['free_bytes'] === null ? 'an unknown amount' : InstallationHeadroom::gigabytes($download['free_bytes'])).' free?'
            .($download['partial_download_present'] ? ' Ollama already holds part of this download and resumes it.' : '')
            .' Molly measures memory, disk, and the runtime again right before it starts.';
    }

    /**
     * The same facts as authorization(), one to a line, printed in full before the question.
     * A prompt hint is cut to one line at the terminal width, so the facts are not a hint.
     *
     * @param  array<string, mixed>  $plan
     */
    private function downloadFacts(array $plan): string
    {
        $download = $plan['download'];

        return implode(PHP_EOL, array_filter([
            'Model: '.$plan['model'],
            'Size: '.InstallationHeadroom::gigabytes($download['bytes'])
                .($download['model'] !== $plan['model'] ? ' (Ollama pulls '.$download['model'].' and derives '.$plan['model'].' from it)' : ''),
            'Destination: '.($download['destination'] ?? 'the Ollama models directory'),
            'Volume: '.($download['volume_name'] ?? 'unknown').', mounted at '.($download['mount_point'] ?? 'an unknown mount point'),
            'Free space: '.($download['free_bytes'] === null ? 'unknown' : InstallationHeadroom::gigabytes($download['free_bytes'])),
            $download['partial_download_present'] ? 'Ollama already holds part of this download and resumes it.' : null,
            'Molly measures memory, disk, and the runtime again right before it starts.',
        ]));
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function document(array $plan): array
    {
        return array_diff_key($plan, ['candidate' => true]);
    }
}
