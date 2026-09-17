# Working on Molly

Keep the CLI thin. Application actions own task execution, verification, and review decisions. Prefer explicit code and existing Laravel behavior over new layers.

Tarpit review and complexity reduction are primary product concerns. Show all seven checks, unresolved findings, and Clever measurements with their limitations. Never report a skipped check as passing or combine measurements into a score.

Apply the unslop skill to all content, including app output and every commit subject and body. Use the practical tone of Laravel documentation. Name the action and explain the result. Avoid em dashes, promotional claims, and vague language. Commit subjects should describe the behavior changed, such as "Show skipped complexity checks". Do not attribute original copy to Taylor Otwell.

Use Laravel Prompts for interactive questions and terminal reports. Preserve JSON output for scripts. Run affected tests before committing. Keep README.md configuration, commands, limitations, and terminology current.
