# Changelog

Notable changes to `particle-academy/agent-flo`.

**Pre-1.0: breaking changes land in MINOR releases.** Read those entries before upgrading.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

---

## [Unreleased]

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
