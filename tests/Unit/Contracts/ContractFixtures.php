<?php

namespace Sifrious\Molly\Tests\Unit\Contracts;

use DateTimeImmutable;
use DateTimeZone;
use Sifrious\Molly\Contracts\AcceptanceTest;
use Sifrious\Molly\Contracts\ApprovalRequirements;
use Sifrious\Molly\Contracts\ExecutionTargetKind;
use Sifrious\Molly\Contracts\ExecutionTargetRequest;
use Sifrious\Molly\Contracts\ExecutionTargetSnapshot;
use Sifrious\Molly\Contracts\HandoffEnvelope;
use Sifrious\Molly\Contracts\RepositoryIdentity;
use Sifrious\Molly\Contracts\RunIdentity;
use Sifrious\Molly\Contracts\TaskContract;
use Sifrious\Molly\Contracts\VerificationOutcome;
use Sifrious\Molly\Contracts\VerifierPolicyMap;
use Sifrious\Molly\Verification\FailureAction;
use Sifrious\Molly\Verification\VerificationState;
use Sifrious\Molly\Verification\VerifierPolicy;

final class ContractFixtures
{
    public const TASK_ID = '11111111-1111-4111-8111-111111111111';

    public const RUN_ID = '22222222-2222-4222-8222-222222222222';

    public const ATTEMPT_ID = '33333333-3333-4333-8333-333333333333';

    public const WORKSPACE_ID = '44444444-4444-4444-8444-444444444444';

    public const CHILD_WORKSPACE_ID = '55555555-5555-4555-8555-555555555555';

    public const HANDOFF_ID = '66666666-6666-4666-8666-666666666666';

    public const TEST_ID = '77777777-7777-4777-8777-777777777777';

    public const SESSION_ID = '88888888-8888-4888-8888-888888888888';

    public const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public const DIGEST = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public static function time(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-18T12:00:00Z', new DateTimeZone('UTC'));
    }

    public static function task(): TaskContract
    {
        return new TaskContract(
            self::TASK_ID,
            'Return Hello from the greeting helper.',
            new RepositoryIdentity('github', 'sifrious', 'molly', 'https://github.com/sifrious/molly'),
            self::WORKSPACE_ID,
            '/tmp/bloom/molly-workspace',
            'bloom/hello',
            self::SHA,
            ['app/Greeting.php'],
            ['tests/Feature/GreetingTest.php'],
            [new AcceptanceTest(self::TEST_ID, 'tests/Feature/GreetingTest.php', self::DIGEST)],
            3,
            VerifierPolicyMap::defaults(),
            ExecutionTargetRequest::local(),
            ApprovalRequirements::defaults(),
        );
    }

    public static function run(): RunIdentity
    {
        return new RunIdentity(
            self::RUN_ID,
            self::ATTEMPT_ID,
            1,
            self::TASK_ID,
            null,
            null,
            self::SESSION_ID,
            'ollama',
            'qwen2.5-coder:7b',
            self::WORKSPACE_ID,
            '/tmp/bloom/molly-workspace',
            'bloom/hello',
            self::SHA,
            self::time(),
            'molly.configuration.v1',
        );
    }

    public static function target(): ExecutionTargetSnapshot
    {
        return new ExecutionTargetSnapshot(
            ExecutionTargetKind::Local,
            'local',
            ['repository_identity', 'workspace_mapping', 'sandbox_profile'],
            false,
            'local',
            self::time(),
            'Local execution is the default.',
        );
    }

    public static function outcome(): VerificationOutcome
    {
        return new VerificationOutcome(
            'pest',
            VerificationState::Fail,
            VerifierPolicy::Required,
            FailureAction::Retry,
            'storage/molly/pest.json',
            self::DIGEST,
            self::time(),
            self::time()->modify('+12 seconds'),
            true,
        );
    }

    public static function handoff(): HandoffEnvelope
    {
        return new HandoffEnvelope(
            self::HANDOFF_ID,
            self::TASK_ID,
            self::RUN_ID,
            self::WORKSPACE_ID,
            self::CHILD_WORKSPACE_ID,
            'Implement Greeting.php against the locked Pest test.',
            ['app/Greeting.php'],
            ['tests/Feature/GreetingTest.php'],
            ['storage/molly/pest.json'],
            ['storage/molly/journal.md'],
            'implement',
            'parent-run:'.self::RUN_ID,
        );
    }
}
