# QuickStart: Molly with Bloom

Bloom is optional. Finish the [standalone QuickStart](quickstart-standalone.md) first; Molly must be installed and passing `molly:doctor` before Bloom adds anything.

With a task saved and the project open in Bloom:

```bash
php artisan molly:bloom-contract demo-greeting \
  --workspace-id=WORKSPACE_UUID \
  --branch=BRANCH \
  --base-sha=MERGE_BASE_SHA
php artisan molly:start demo-greeting
php artisan molly:show RUN_ID --verbose
php artisan molly:approve demo-greeting --approve
```

Bloom binds `.molly/bloom-contract.json` to the workspace, shows the task, and unlocks its pull request controls after the approval. Molly does not open or merge pull requests; `molly:pr-opened` and `molly:merged` record what a person did.

[Molly with Bloom](bloom.md) explains what Bloom adds today, what still runs in the terminal, and how to install the Bloom plugin.
