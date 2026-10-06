<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\Fluent\AssertableJson;
use Sifrious\Molly\Actions\CreatePlan;
use Sifrious\Molly\Actions\SeamWorkflow;
use Sifrious\Molly\Mcp\MollySeam;
use Sifrious\Molly\Mcp\MollySeamRead;
use Sifrious\Molly\Mcp\MollyServer;
use Sifrious\Molly\Mcp\SeamInstructionsPrompt;
use Sifrious\Molly\Mcp\SeamRunResource;

require_once __DIR__.'/../Support/SeamFixtures.php';

beforeEach(function () {
    $this->seamWorkspace = seamApplicationFixture();
});

afterEach(fn () => File::deleteDirectory($this->seamWorkspace));

it('returns equivalent CLI and MCP generation plans', function () {
    $plan = app(CreatePlan::class)->handle('Verify readiness.', false);
    $contractPath = tempnam(sys_get_temp_dir(), 'molly-contract-');
    try {
        file_put_contents($contractPath, json_encode(seamHttpContract()));
        expect(Artisan::call('molly:seam', ['operation' => 'plan', '--workspace' => $this->seamWorkspace,
            '--seam' => 'laravel-framework/controllers', '--plan' => $plan->id, '--contract' => $contractPath, '--json' => true]))->toBe(0);
        $cli = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        MollyServer::tool(MollySeam::class, ['operation' => 'plan', 'workspace' => $this->seamWorkspace,
            'seam' => 'laravel-framework/controllers', 'plan' => $plan->id, 'contract' => seamHttpContract()])
            ->assertOk()->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('revision_id', $cli['revision_id'])->where('revision_digest', $cli['revision_digest'])
            ->where('preview.sha256', $cli['preview']['sha256'])->where('completed', false)->etc());
        MollyServer::resource(SeamRunResource::class, ['revision' => $cli['revision_id']])->assertOk()->assertSee($cli['revision_digest']);
        MollyServer::prompt(SeamInstructionsPrompt::class, ['revision' => $cli['revision_id']])->assertOk()->assertSee($cli['revision_digest']);
    } finally {
        unlink($contractPath);
    }
});

it('rejects client submitted success and refuses out of order execution over MCP', function () {
    MollyServer::tool(MollySeam::class, ['operation' => 'list', 'workspace' => $this->seamWorkspace, 'passed' => true])
        ->assertHasErrors()->assertStructuredContent(fn (AssertableJson $json) => $json->where('error.code', 'INPUT_INVALID')->etc());
    $plan = app(CreatePlan::class)->handle('Verify readiness.', false);
    $workflow = app(SeamWorkflow::class);
    $revision = $workflow->handle(['operation' => 'plan', 'workspace' => $this->seamWorkspace,
        'seam' => 'laravel-framework/controllers', 'plan' => $plan->id, 'contract' => seamHttpContract()]);
    $workflow->handle(['operation' => 'write', 'revision' => $revision['revision_id'], 'digest' => $revision['revision_digest']]);
    // A real persistent queue configuration is required even before dispatch.
    config(['queue.default' => 'database', 'queue.connections.database.retry_after' => 3700]);
    MollyServer::tool(MollySeam::class, ['operation' => 'advance', 'revision' => $revision['revision_id'],
        'digest' => $revision['revision_digest'], 'step' => 'post', 'key' => 'forged-post'])
        ->assertHasErrors()->assertStructuredContent(fn (AssertableJson $json) => $json->where('error.code', 'PLAN_STEP_NOT_ALLOWED')->etc());
});

it('refuses mutations even when called through the read-only tool', function () {
    MollyServer::tool(MollySeamRead::class, ['operation' => 'cancel', 'revision' => 'anything'])
        ->assertHasErrors()->assertStructuredContent(fn (AssertableJson $json) => $json->where('error.code', 'PLAN_STEP_NOT_ALLOWED')->etc());
});
