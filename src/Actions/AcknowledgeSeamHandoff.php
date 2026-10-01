<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\Contracts\LifecycleEventType;
use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Models\SeamRevision;
use Sifrious\Molly\Seams\SeamError;
use Sifrious\Molly\Workspace;

final class AcknowledgeSeamHandoff
{
    public function __construct(private InspectSeamRun $inspect, private RecordSeamAttempt $evidence, private RecordSeamEvent $events) {}

    public function handle(string $id, string $digest, string $candidateDigest, string $evidenceDigest, string $recipient): array
    {
        $revision = SeamRevision::findOrFail($id);
        if (! preg_match('/\A[a-zA-Z0-9:_-]{1,100}\z/D', $recipient)) {
            throw new SeamError('HANDOFF_INVALID', 'Name the receiving agent with a bounded identifier.', $id);
        }

        return (new Workspace($revision->snapshot['workspace']))->exclusively(function () use ($revision, $digest, $candidateDigest, $evidenceDigest, $recipient): array {
            $revision->refresh();
            $status = $this->inspect->handle($revision->id);
            $expected = $status['handoff'] ?? null;
            $ack = ['recipient' => $recipient, 'revision_digest' => $digest, 'candidate_digest' => $candidateDigest, 'evidence_digest' => $evidenceDigest];
            $previous = $revision->results['handoff_acknowledgement'] ?? null;
            if ($expected === null || $expected['revision_digest'] !== $digest || $expected['candidate_digest'] !== $candidateDigest
                || $expected['evidence_digest'] !== $evidenceDigest || ($previous !== $ack && ! in_array('acknowledge', $status['allowed_actions'], true))) {
                throw new SeamError('HANDOFF_INVALID', 'Read the current handoff and verify its exact evidence identity before acknowledging it.', $revision->id);
            }
            foreach ($revision->results as $result) {
                if (isset($result['state'])) {
                    $this->evidence->verify($result['artifacts'] ?? [], $revision->snapshot['workspace']);
                }
            }

            if ($previous !== null && $previous !== $ack) {
                throw new SeamError('HANDOFF_INVALID', 'A different acknowledgement already exists for this revision.', $revision->id);
            }
            if ($previous === null) {
                $attempt = Run::findOrFail($revision->results['cleanup']['attempt_id']);
                $this->events->handle($revision, $attempt, LifecycleEventType::HandoffRequested, $expected);
                $this->events->handle($revision, $attempt, LifecycleEventType::HandoffAcknowledged, $ack);
                $revision->update(['results' => [...$revision->results, 'handoff_acknowledgement' => $ack]]);
            }

            return $ack;
        });
    }
}
