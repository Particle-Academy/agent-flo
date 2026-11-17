# Using particle-academy/agent-flo — guide for agents

How an agent installs and uses this package in a consumer app. What the package
is, is in [README.md](./README.md). Notes for working ON this repo's code are in
[CONTRIBUTING.md](./CONTRIBUTING.md).

## Install

Not published yet. Once it is:

```bash
composer require particle-academy/agent-flo
```

- **Requires:** PHP `^8.4`, Laravel `^13.0`, `particle-academy/prism-harness`,
  `particle-academy/fancy-flow-php` and `particle-academy/fancy-flow-mcp`.
- The service provider (`AgentFlo\Laravel\AgentFloServiceProvider`) is
  auto-discovered. Config lives in `config/agent-flo.php`.

## Find it on the Fancy MCP server

Not yet listed on the Fancy MCP catalog server (`https://ui.particle.academy/mcp`,
MCP id `fancy-ui`); it appears there once published. Server-side packages are
found with `search-backend-packages` (stack `php`).

## API essentials

- **`AgentFlo`** (container singleton): `subagents($tool, $vocabulary)` for a
  parent mode's `subagents`, `bind($parentSession, FloScope)` each turn,
  `delegate()`, `tools()`, `proposals()`, `approvals()`.
- **`FloScope`** says who and which tenant: `person`, `system`, `actingFor`. It
  comes from host code only.
- **Stores are the host's.** Implement `Store\FlowStore` and `Store\RunStore`,
  or use `FancyFlowPhpStore::singleTenant()` / `::scopedBy()`. `InMemoryStore`
  is the reference. Run `Testing\StoreConformance::run($fixture)` against your
  own adapter.
- **Proposals by default.** Flo's `flo_*` tools read and propose; a proposal is
  approved by host code through `Proposals` (person scope only), never by a tool.
- **Config keys** (`config/agent-flo.php`): `vocabulary` (the host's words for
  agent, flow, flows, step, steps, run, runs), `dangerously_disable_approvals`,
  `system_scope_may_change`, `max_steps`, `proposal_ttl_days`.

## Traps

- **Scope, tenant and actor never come from a tool argument**; unknown arguments
  are refused.
- **A delegation that read run data can only propose**, unless the
  `after_run_data` approval is turned off.
- **Missing and forbidden are the same answer**, `not_found`.
- **Approvals are on by default.** `dangerously_disable_approvals` takes `true`
  or a list of names (`change`, `after_run_data`); an unknown name stops boot,
  and while anything is off `package:discover` and `optimize` print a warning
  and `php artisan agent-flo:approvals --ci` exits non-zero.
- **Vocabulary is developer config**, never tenant or user text: it reaches a
  model prompt, and unsafe words are refused at boot.
- A system scope (Flo called from inside a run) only reads unless
  `system_scope_may_change` is `true`.
