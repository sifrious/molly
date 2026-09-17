<?php

namespace Sifrious\Molly;

use RuntimeException;

class PlanningGuide
{
    /** @return array<string, mixed> */
    public function graph(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../resources/planning/guide.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function version(): string
    {
        return $this->graph()['version'];
    }

    /** @return list<array<string, mixed>> */
    public function steps(): array
    {
        return $this->graph()['steps'];
    }

    /**
     * @param  array<string, string>  $answers
     * @return array<string, mixed>|null
     */
    public function nextStep(array $answers): ?array
    {
        foreach ($this->steps() as $step) {
            if (! isset($answers[$step['id']])) {
                return $step;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $answers
     * @return list<array<string, mixed>>
     */
    public function sourcesFor(string $description, array $answers = []): array
    {
        $text = mb_strtolower($description.' '.implode(' ', $answers));
        $step = $this->nextStep($answers);
        $references = [];
        foreach ($this->steps() as $reviewed) {
            if (isset($answers[$reviewed['id']]) || $reviewed['id'] === ($step['id'] ?? null)) {
                $references = [...$references, ...$reviewed['source_ids']];
            }
        }
        $selected = [];
        foreach ($this->graph()['sources'] as $source) {
            $score = in_array($source['id'], $references, true) ? 1 : 0;
            if ($score > 0 && $source['id'] === 'mary-tarpit') {
                $score += 100;
            }
            foreach ($source['topics'] as $topic) {
                if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote(mb_strtolower($topic), '/').'(?![\p{L}\p{N}])/u', $text)) {
                    $score += 2;
                }
            }
            if ($score > 0) {
                $selected[] = ['score' => $score, 'source' => $source];
            }
        }
        usort($selected, fn (array $left, array $right): int => ($right['score'] <=> $left['score']) ?: strcmp($left['source']['id'], $right['source']['id']));

        return array_map(fn (array $entry): array => $this->source($entry['source']['id']), array_slice($selected, 0, 5));
    }

    /** @return array<string, mixed> */
    public function source(string $id): array
    {
        foreach ($this->graph()['sources'] as $source) {
            if ($source['id'] !== $id) {
                continue;
            }
            if (! preg_match('~\Asources/[a-z0-9-]+\.md\z~', $source['path'])) {
                throw new RuntimeException('GUIDE_SOURCE_INVALID: The bundled source path is invalid.');
            }
            $content = file_get_contents(__DIR__.'/../resources/planning/'.$source['path']);
            if (isset($source['sha256']) && ! hash_equals($source['sha256'], hash('sha256', $content))) {
                throw new RuntimeException('GUIDE_SOURCE_CHANGED: The bundled source does not match its recorded digest.');
            }

            return [...$source, 'content' => $content];
        }

        throw new RuntimeException('GUIDE_SOURCE_NOT_FOUND: Choose a source from the bundled graph.');
    }
}
