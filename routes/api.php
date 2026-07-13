<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AlertEventController;
use App\Http\Controllers\Api\AlertPolicyController;
use App\Http\Controllers\Api\AlertTransportController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BackupSettingController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\CredentialController;
use App\Http\Controllers\Api\DeviceBackupController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\DiscoverDeviceController;
use App\Http\Controllers\Api\DiscoveryCandidateController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\InterfaceController;
use App\Http\Controllers\Api\InterfaceSampleController;
use App\Http\Controllers\Api\LinkController;
use App\Http\Controllers\Api\MailSettingController;
use App\Http\Controllers\Api\MapController;
use App\Http\Controllers\Api\OutageController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\SubnetController;
use App\Http\Controllers\Api\UserController;
use App\Http\Middleware\RestrictWritesToAdmins;
use Illuminate\Support\Facades\Route;

// --- Public ---------------------------------------------------------------
// Ops health probe (DB + Redis) - 200 healthy / 503 degraded. Stays
// public so a load-balancer can probe it without credentials.
Route::get('health', HealthController::class)->name('health');

// Public "contact sales" form (sales demo). No auth - an anonymous visitor may submit;
// rate-limited to deter abuse.
Route::post('contact', [ContactController::class, 'store'])->middleware('throttle:5,1')->name('contact');

// Login/logout live on the web group (session + CSRF) - see routes/web.php.

// --- Authenticated (everything else) --------------------------------------
// `RestrictWritesToAdmins` makes non-admin operators read-only across the whole API
// - GETs pass, any write from a non-admin is 403 (except their own password).
Route::middleware(['auth:sanctum', RestrictWritesToAdmins::class])->group(function (): void {
    Route::get('user', [AuthController::class, 'user'])->name('user');
    // Self-service password change.
    Route::put('account/password', [AuthController::class, 'updatePassword'])
        ->middleware('throttle:6,1')->name('account.password.update');

    // Operator accounts. Any authenticated operator can *view* the roster
    // (the controller shapes the payload by tier - non-admins get no sensitive fields);
    // creating/editing/deleting is admin-only.
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::middleware(['admin', 'throttle:10,1'])->group(function (): void {
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    });

    // Device CRUD + map position.
    Route::patch('devices/{device}/position', [DeviceController::class, 'updatePosition'])
        ->name('devices.position');
    // Bulk firmware upgrade - one isolated job per device. Before the
    // resource so `devices/upgrade` isn't shadowed by `devices/{device}`.
    // Dry-run the dependency checks first; both before the resource.
    Route::post('devices/upgrade/preflight', [DeviceController::class, 'upgradePreflight'])
        ->middleware('throttle:10,1')->name('devices.upgrade.preflight');
    Route::post('devices/upgrade', [DeviceController::class, 'upgrade'])
        ->middleware('throttle:10,1')->name('devices.upgrade');
    Route::apiResource('devices', DeviceController::class);

    // A device's interfaces (link binder picks each end from these).
    Route::get('devices/{device}/interfaces', [InterfaceController::class, 'index'])
        ->name('devices.interfaces');
    // On-demand interface (re)discovery - when a device shows no
    // interfaces, an operator can trigger discovery instead of waiting for the loop.
    Route::post('devices/{device}/discover', DiscoverDeviceController::class)
        ->middleware('throttle:10,1')->name('devices.discover');
    // Interface speed is read-only from SNMP - the bandwidth override
    // moved to the link (PUT /links/{link}); there's no longer an interface write path.

    // Device config backups - control plane for the Rusted engine.
    // Config (opt-in + driver) and "back up now" are writes (admin-only via the group);
    // history + latest-config are read-through proxies any operator can view. `run` is
    // rate-limited (each hit SSHes to the device via Rusted).
    Route::put('devices/{device}/backup-config', [DeviceBackupController::class, 'config'])
        ->name('devices.backup.config');
    Route::post('devices/{device}/backups', [DeviceBackupController::class, 'run'])
        ->middleware('throttle:10,1')->name('devices.backups.run');
    // Bootstrap key-based SSH on a MikroTik over the API (installs a generated key).
    Route::post('devices/{device}/provision-ssh-key', [DeviceBackupController::class, 'provisionKey'])
        ->middleware('throttle:6,1')->name('devices.provision-ssh-key');
    Route::get('devices/{device}/backups', [DeviceBackupController::class, 'history'])
        ->name('devices.backups.history');
    Route::get('devices/{device}/backups/latest', [DeviceBackupController::class, 'latest'])
        ->name('devices.backups.latest');
    // Git-backed version history + view an old version + diff between versions.
    Route::get('devices/{device}/backups/versions', [DeviceBackupController::class, 'versions'])
        ->name('devices.backups.versions');
    Route::get('devices/{device}/backups/config', [DeviceBackupController::class, 'configAt'])
        ->name('devices.backups.config-at');
    Route::get('devices/{device}/backups/diff', [DeviceBackupController::class, 'diff'])
        ->name('devices.backups.diff');

    // Recent history: a bucketed util/bps series for one interface, or the
    // whole device's total throughput (bps summed across its interfaces).
    Route::get('interfaces/{interface}/samples', [InterfaceSampleController::class, 'index'])
        ->name('interfaces.samples');
    Route::get('devices/{device}/samples', [InterfaceSampleController::class, 'device'])
        ->name('devices.samples');
    // Recent history: bucketed cpu/mem/temp series for one device (resource chart).
    Route::get('devices/{device}/metric-samples', [InterfaceSampleController::class, 'metrics'])
        ->name('devices.metric-samples');

    // Topology links (interface-to-interface). Update re-binds either end.
    Route::apiResource('links', LinkController::class)->only(['index', 'store', 'update', 'destroy']);

    // Multiple maps: tree + per-map device placements/positions + inter-map links.
    Route::apiResource('maps', MapController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
    Route::patch('maps/{map}/positions/{device}', [MapController::class, 'savePosition'])->name('maps.positions.save');
    Route::patch('maps/{map}/links/{link}/position', [MapController::class, 'saveLinkPosition'])->name('maps.links.position');
    Route::post('maps/{map}/devices', [MapController::class, 'addDevice'])->name('maps.devices.add');
    Route::delete('maps/{map}/devices/{device}', [MapController::class, 'removeDevice'])->name('maps.devices.remove');

    // Outage timeline - ?device_id= , ?state=open|closed.
    Route::get('outages', [OutageController::class, 'index'])->name('outages.index');

    // MikroTik "The Dude" import (FR-Dude): upload a dude.db, then poll the run for
    // live stage/percent/ETA; cancel stops it cleanly.
    Route::get('imports', [ImportController::class, 'index'])->name('imports.index');
    Route::post('imports', [ImportController::class, 'store'])->name('imports.store');
    Route::get('imports/{import}', [ImportController::class, 'show'])->name('imports.show');
    Route::post('imports/{import}/cancel', [ImportController::class, 'cancel'])->name('imports.cancel');

    // Settings: editable engine tunables + credential management.
    Route::get('settings', [SettingController::class, 'index'])->name('settings.index');
    Route::put('settings', [SettingController::class, 'update'])->name('settings.update');
    // Outgoing mail server: SMTP config + a test send.
    Route::get('settings/mail', [MailSettingController::class, 'show'])->name('settings.mail.show');
    Route::put('settings/mail', [MailSettingController::class, 'update'])->name('settings.mail.update');
    Route::post('settings/mail/test', [MailSettingController::class, 'test'])
        ->middleware('throttle:6,1')->name('settings.mail.test');
    // Config-backup engine: Rusted URL/token + default SSH login + a
    // reachability check. Token/password encrypted at rest, never returned.
    Route::get('settings/backup', [BackupSettingController::class, 'show'])->name('settings.backup.show');
    Route::put('settings/backup', [BackupSettingController::class, 'update'])->name('settings.backup.update');
    Route::get('settings/backup-schedule', [BackupSettingController::class, 'schedule'])->name('settings.backup.schedule.show');
    Route::put('settings/backup-schedule', [BackupSettingController::class, 'updateSchedule'])->name('settings.backup.schedule.update');
    Route::post('backups/run-all', [BackupSettingController::class, 'runAll'])
        ->middleware('throttle:6,1')->name('backups.run-all');
    Route::post('settings/backup/test', [BackupSettingController::class, 'test'])
        ->middleware('throttle:6,1')->name('settings.backup.test');
    Route::apiResource('credentials', CredentialController::class)->only(['index', 'store', 'update', 'destroy']);

    // Remote agents (probes): list is readable by any operator; enrol/delete are admin-only
    // (RestrictWritesToAdmins). Enrolment returns the token once.
    Route::apiResource('agents', AgentController::class)->only(['index', 'store', 'destroy']);

    // Alerting: policies, transports (test-send is rate-limited), event log.
    Route::apiResource('alert-policies', AlertPolicyController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::apiResource('alert-transports', AlertTransportController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('alert-transports/{transport}/test', [AlertTransportController::class, 'test'])
        ->middleware('throttle:6,1')->name('alert-transports.test');
    Route::get('alert-events', [AlertEventController::class, 'index'])->name('alert-events.index');

    // Auto-discovery: authorized scan ranges + the candidate review queue.
    Route::apiResource('subnets', SubnetController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('subnets/{subnet}/scan', [SubnetController::class, 'scan'])
        ->middleware('throttle:10,1')->name('subnets.scan');
    Route::get('discovery-candidates', [DiscoveryCandidateController::class, 'index'])
        ->name('discovery-candidates.index');
    Route::post('discovery-candidates/{candidate}/approve', [DiscoveryCandidateController::class, 'approve'])
        ->name('discovery-candidates.approve');
    Route::post('discovery-candidates/{candidate}/ignore', [DiscoveryCandidateController::class, 'ignore'])
        ->name('discovery-candidates.ignore');
});
