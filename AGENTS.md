# agent-flo code map

`AgentFlo\` is a PHP 8.4 library for Laravel 13: a headless subagent that manages
fancy-flow flows inside an app on prism-harness. Design: the envelope's
`.ai/plans/flo.md`, with the owner's rulings at its top. Nothing in `src/` uses
Laravel Foundation helpers (`now()`, `config()`, `app()`); read through the container.

## Where things are

- `AgentFlo` (singleton): `subagents($tool, $vocabulary)` for a parent mode's
  `subagents`, `bind($parentSession, FloScope)` each turn, `delegate()`, `tools()`,
  `proposals()`, `approvals()`.
- `FloScope`: who and which tenant, from host code only. `person`, `system`, `actingFor`.
- `Vocabulary\Vocabulary`: the host's words, refused at boot when unsafe.
- `Store\{FlowStore, RunStore}`: the host's contract. `InMemoryStore` is the reference;
  `FancyFlowPhpStore` has no public constructor (`singleTenant()` or `scopedBy()`).
  `Testing\StoreConformance::run($fixture)` is the kit a host runs on its own adapter.
- `Delegations\*`: one row per parent turn: scope, `tainted`, tools called, drafts.
  `DelegationDraftStore` holds fancy-flow-mcp's drafts to one delegation.
- `Tools\FloTools`: `flo_*` (read, propose) plus fancy-flow-mcp's own tool classes,
  called in process. `Untrusted::frame` is the only way author text leaves a tool.
- `Proposals\Proposals`: create, find, approve (person scope only), reject.
- `Approvals\Approvals`: the approval-override pattern; `resources/hitl.json` declares it.
- `Guide\FloMode` + `KindKnowledge`: the mode prompt. Kind facts are generated from
  the registry; the hand-written preamble names no kind id.

## Invariants a change must keep

- **Scope, tenant and actor never come from a tool argument.** Unknown arguments refuse.
- **No tool can approve**, and no tool name contains `approve`. Approval is host code.
- **A delegation that read run data can only propose** unless `after_run_data` is off.
  The taint is written before the run's content is returned.
- **Missing and forbidden are one answer**, `not_found`, from tools and stores.
- **Everything someone else wrote travels under `untrusted`**, re-sanitised.
- **Approvals are on by default.** Off is only `agent-flo.dangerously_disable_approvals`,
  an unknown name stops boot, and while anything is off the warning goes into
  `package:discover` and `optimize` output. Adding an approval means adding it to
  `Approvals::DECLARED` and `resources/hitl.json`; a test fails until both agree.

## Tests

Pest 5, hand-built kernel (`tests/Support/TestApp.php`, no testbench), in-memory SQLite,
and `ScriptedProvider`, which executes the tools a run offers. No model is ever called.
