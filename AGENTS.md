# Working on Molly

Keep the CLI thin. Application actions own task execution, verification, and review decisions. Prefer explicit code and existing Laravel behavior over new layers.

Tarpit review and complexity reduction are primary product concerns. Show all seven checks, unresolved findings, and Clever measurements with their limitations. Never report a skipped check as passing or combine measurements into a score.

Apply the unslop skill to all content, including app output and every commit subject and body. Use the practical tone of Laravel documentation. Name the action and explain the result. Avoid em dashes, promotional claims, and vague language. Commit subjects should describe the behavior changed, such as "Show skipped complexity checks". Do not attribute original copy to Taylor Otwell.

Use Laravel Prompts for interactive questions and terminal reports. Preserve JSON output for scripts. Run affected tests before committing. Keep README.md configuration, commands, limitations, and terminology current.

## Graph store and records

Molly's knowledge graphs use an isolated SQLite store. Keep graph records portable and Burdgen-compatible. Every node and edge must carry source provenance. Graph generation stays deterministic: the same inputs must produce the same snapshot.

## Construction

Resolve services through Laravel's container (constructor injection / zero-config binding). Construct value objects, enums, and immutable records with `new` or named constructors. Do not invent a new interface, manager, registry, or DTO until a second concrete caller or a real behavior variation exists.

## Transport and safety

Keep Http, Console, Livewire, and MCP thin: they dispatch and present; they do not own run completion or verification decisions. Do not simplify safety boundaries (workspace isolation, protected tests, approval gates, or destructive MCP flags) to shorten a file.
