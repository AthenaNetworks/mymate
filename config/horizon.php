<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    |
    | This name appears in notifications and in the Horizon UI. Unique names
    | can be useful while running multiple instances of Horizon within an
    | application, allowing you to identify the Horizon you're viewing.
    |
    */

    'name' => env('HORIZON_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    */

    'waits' => [
        'redis:default' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

    // Kept deliberately short. On a large fleet the poll loop runs a high, steady rate of jobs, so
    // holding an hour of completed/recent metadata (plus a week of everything else) is a lot of
    // Redis for dashboard history nobody reads. Failures are still kept for a few days.
    'trim' => [
        'recent' => 15,
        'pending' => 15,
        'completed' => 15,
        'recent_failed' => 4320,
        'failed' => 4320,
        'monitored' => 4320,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs within the Horizon dashboard. This setting may be
    | used to fully remove any noisy jobs from the completed jobs list.
    |
    */

    // The recurring poll jobs run constantly and would otherwise dominate the completed/recent
    // lists (and the Redis they cost) with routine, uninteresting successes. Silence them so
    // Horizon stops recording their completed metadata - they still show up if they FAIL.
    'silenced' => [
        \App\Jobs\PingSweepJob::class,
        \App\Jobs\PollInterfacesBatchJob::class,
        \App\Jobs\PollDeviceMetricsBatchJob::class,
        \App\Jobs\PollSensorsBatchJob::class,
        \App\Jobs\EvaluateAlertsJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    */

    'defaults' => [
        // Up/down sweeps: one job per tick for the whole fleet - light, frequent.
        'supervisor-ping' => [
            'connection' => 'redis',
            'queue' => ['ping'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            // Raise on large fleets so sharded ping sweeps (mymate.ping.shards) run in parallel.
            'maxProcesses' => (int) env('MYMATE_PING_PROCESSES', 1),
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],

        // Throughput: one sharded batch job per shard per tick - the scalable pool.
        // Scale by raising maxProcesses (and `mymate.poll.shards`) with the fleet.
        'supervisor-poll' => [
            'connection' => 'redis',
            'queue' => ['poll'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'size',
            'maxProcesses' => 3,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],

        // Discovery scans: isolated so a slow sweep never delays polling.
        'supervisor-scan' => [
            'connection' => 'redis',
            'queue' => ['scan', 'default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],

        // Firmware upgrades: isolated so a rebooting device never delays
        // polling/discovery. Long timeout -  waits for a device to come back.
        'supervisor-upgrade' => [
            'connection' => 'redis',
            'queue' => ['upgrade'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 900,
            'nice' => 0,
        ],

        // Device config backups: isolated + long timeout - a slow SSH
        // capture in the Rusted sidecar must never delay polling/discovery/upgrades.
        'supervisor-backup' => [
            'connection' => 'redis',
            'queue' => ['backup'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 2,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 300,
            'nice' => 0,
        ],

        // Dude imports (FR-Dude): isolated + long timeout - extraction (Python) plus
        // upserting millions of history rows must never block polling/discovery.
        'supervisor-import' => [
            'connection' => 'redis',
            'queue' => ['import'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 512,
            'tries' => 1,
            // Match the job's whole-job ceiling (mymate.import.job_timeout) so the worker
            // never kills a long history import before the job's own timeout fires.
            'timeout' => (int) env('MYMATE_IMPORT_JOB_TIMEOUT', 21600),
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-ping' => ['maxProcesses' => (int) env('MYMATE_PING_PROCESSES', 2)],
            // Raise with the fleet: ~ shards you want running concurrently.
            'supervisor-poll' => ['maxProcesses' => 20, 'balanceMaxShift' => 5, 'balanceCooldown' => 3],
            'supervisor-scan' => ['maxProcesses' => 4],
            'supervisor-upgrade' => ['maxProcesses' => 4],
            'supervisor-backup' => ['maxProcesses' => 3],
            'supervisor-import' => ['maxProcesses' => 1],
        ],

        'local' => [
            'supervisor-ping' => ['maxProcesses' => 1],
            'supervisor-poll' => ['maxProcesses' => 3],
            'supervisor-scan' => ['maxProcesses' => 1],
            'supervisor-upgrade' => ['maxProcesses' => 1],
            'supervisor-backup' => ['maxProcesses' => 1],
            'supervisor-import' => ['maxProcesses' => 1],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Watcher Configuration
    |--------------------------------------------------------------------------
    |
    | The following list of directories and files will be watched when using
    | the `horizon:listen` command. Whenever any directories or files are
    | changed, Horizon will automatically restart to apply all changes.
    |
    */

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
