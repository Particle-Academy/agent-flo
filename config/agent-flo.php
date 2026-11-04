<?php

declare(strict_types=1);

return [

    /*
    | The host's words. Developer config, per app: never tenant or user text,
    | because these reach a model prompt. Refused at boot when they break the
    | pattern. Keys: agent, flow, flows, step, steps, run, runs.
    */
    'vocabulary' => [],

    /*
    | Every approval is ON. Setting this turns them off: `true` for all, or a list
    | of names (`change`, `after_run_data`). While anything is off, `package:discover`
    | and `php artisan optimize` print a warning naming each one, and
    | `php artisan agent-flo:approvals --ci` exits non-zero.
    */
    'dangerously_disable_approvals' => env('AGENT_FLO_DANGEROUSLY_DISABLE_APPROVALS'),

    /*
    | A system scope (Flo called from inside a run) only reads unless this is true.
    */
    'system_scope_may_change' => false,

    'max_steps' => 12,

    'proposal_ttl_days' => 7,
];
