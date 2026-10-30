<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BackupVerification;
use App\Models\NotificationDelivery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Health endpoints (developer guide 15.4): readiness without secrets, topology or customer data.
 *  GET /api/v1/health          public, minimal: ok | degraded (503 when the database is down) - for load balancers / uptime monitors
 *  GET /api/v1/health/details  needs audit_log.view: per-component status, counts and ages
 */
class HealthController extends Controller
{
    public function ping()
    {
        $db = $this->database();
        return response()->json(['status' => $db['ok'] ? 'ok' : 'degraded', 'time' => now()->toIso8601String()], $db['ok'] ? 200 : 503);
    }

    public function details()
    {
        $checks = [
            'database' => $this->database(),
            'cache' => $this->cache(),
            'storage' => $this->storage(),
            'queue' => $this->queue(),
            'scheduler' => $this->scheduler(),
            'backup' => $this->backup(),
            'notifications' => $this->notifications(),
        ];
        $status = collect($checks)->every(fn ($c) => $c['ok']) ? 'ok' : 'degraded';

        return response()->json(['status' => $status, 'time' => now()->toIso8601String(), 'checks' => $checks], $checks['database']['ok'] ? 200 : 503);
    }

    private function database(): array
    {
        try {
            $t = microtime(true);
            DB::select('select 1');
            return ['ok' => true, 'latency_ms' => (int) round((microtime(true) - $t) * 1000)];
        } catch (\Throwable) {
            return ['ok' => false];
        }
    }

    private function cache(): array
    {
        try {
            $k = 'ops:health:' . bin2hex(random_bytes(4));
            Cache::put($k, '1', 10);
            $ok = Cache::get($k) === '1';
            Cache::forget($k);
            return ['ok' => $ok, 'driver' => config('cache.default')];
        } catch (\Throwable) {
            return ['ok' => false, 'driver' => config('cache.default')];
        }
    }

    private function storage(): array
    {
        try {
            $disk = Storage::disk(config('ops.files.disk'));
            $name = '.health-' . bin2hex(random_bytes(4));
            $disk->put($name, 'ok');
            $ok = $disk->get($name) === 'ok';
            $disk->delete($name);
            return ['ok' => $ok];
        } catch (\Throwable) {
            return ['ok' => false];
        }
    }

    private function queue(): array
    {
        $out = ['ok' => true, 'driver' => config('queue.default')];
        try {
            if (Schema::hasTable('failed_jobs')) {
                $out['failed_jobs'] = DB::table('failed_jobs')->count();
                $out['ok'] = $out['failed_jobs'] < 50;
            }
            if (config('queue.default') === 'database' && Schema::hasTable('jobs')) {
                $out['pending_jobs'] = DB::table('jobs')->count();
                $oldest = DB::table('jobs')->min('available_at');
                $out['oldest_pending_seconds'] = $oldest ? max(0, now()->timestamp - (int) $oldest) : 0;
                $out['ok'] = $out['ok'] && $out['oldest_pending_seconds'] < 600;   // a worker is clearly not running
            }
        } catch (\Throwable) {
            $out['ok'] = false;
        }
        return $out;
    }

    /** The scheduler writes a heartbeat every minute (ops:heartbeat). No heartbeat for 3 minutes = scheduler is not running. */
    private function scheduler(): array
    {
        $ts = Cache::get('ops:scheduler:heartbeat');
        if (!$ts) return ['ok' => false, 'last_run_seconds_ago' => null];
        $age = max(0, now()->timestamp - (int) $ts);
        return ['ok' => $age <= (int) config('ops.health.scheduler_max_age', 180), 'last_run_seconds_ago' => $age];
    }

    private function backup(): array
    {
        try {
            $d = BackupVerification::whereHas('result', fn ($q) => $q->where('code', 'verified_ok'))->max('backup_date');
            $days = $d ? (int) now()->startOfDay()->diffInDays(\Illuminate\Support\Carbon::parse($d)->startOfDay(), true) : null;
            return ['ok' => $days !== null && $days <= (int) config('ops.health.backup_max_age_days', 14), 'days_since_verified_ok' => $days];
        } catch (\Throwable) {
            return ['ok' => false, 'days_since_verified_ok' => null];
        }
    }

    private function notifications(): array
    {
        try {
            $since = now()->subDay();
            $failed = NotificationDelivery::where('created_at', '>=', $since)->whereHas('status', fn ($q) => $q->where('code', 'failed'))->count();
            $total = NotificationDelivery::where('created_at', '>=', $since)->count();
            return ['ok' => $total === 0 || ($failed / $total) <= 0.25, 'failed_24h' => $failed, 'total_24h' => $total];
        } catch (\Throwable) {
            return ['ok' => false];
        }
    }
}
