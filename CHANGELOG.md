# Changelog

Notable changes to `particle-academy/agent-flo`.

**Pre-1.0: breaking changes land in MINOR releases.** Read those entries before upgrading.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

---

## [Unreleased]

## [0.1.0] - 2026-11-08

### Added

- Package skeleton: `composer.json` (PHP `^8.4`, Laravel 13, `prism-harness` `>=0.22`,
  `fancy-flow-php` `>=0.66`, `fancy-flow-mcp` `>=0.6`), a Pest 5 suite on a hand-built
  Laravel 13 kernel with no testbench, and CI copied from `charlotte`. `laravel/framework`
  is a dev dependency under the estate's carve-out for the framework a package integrates
  with.
- `FloScope`: who a call is for and in which tenant, always from host code. `person`,
  `system` (inside a run), and an explicit `actingFor`.
- `Vocabulary`: the host's words for the agent, flows, steps and runs, refused at boot when
  they could break a prompt, and a validator for the one tool name a parent sees.
- The store contract (`FlowStore`, `RunStore`), `InMemoryStore`, `FancyFlowPhpStore`
  (no public constructor: `singleTenant()` or `scopedBy()`), and
  `Testing\StoreConformance`, the kit a host runs against its own adapter.
- Read tools `flo_list`, `flo_get`, `flo_runs`, `flo_run`, with author text under
  `untrusted`. Reading a run taints the delegation.
- fancy-flow-mcp's authoring tools, called in process against a draft store held to one
  delegation, and `flo_propose`, which turns a draft into a proposal.
- Proposals: stored with a code-computed diff, approved only by host code with a person's
  scope, re-validated at approval, and `conflicted` with nothing written if the flow moved.
- The `agent_flo` harness mode with kind facts generated from the registry, and
  `AgentFlo::subagents()` / `bind()` for a parent mode on the harness loop.
- Approvals on by default; `agent-flo.dangerously_disable_approvals` turns them off, with
  a warning in `package:discover` and `php artisan optimize` output, and
  `php artisan agent-flo:approvals --ci` to fail a build. Declared in `resources/hitl.json`.
  `optimize` runs its tasks with their output discarded, so the command writes its
  warnings to stderr when silenced; measured on a real Laravel 13 app, where it otherwise
  printed only the task name.
