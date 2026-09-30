<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneAttendanceEvidence extends Command
{
    protected $signature = 'hr:prune-attendance-evidence {--days= : Override the configured retention period}';

    protected $description = 'Null the precise-location evidence (coordinates, IP, user agent) on old attendance verifications, keeping the result, distance and review trail';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('hr_attendance_geofence.evidence_retention_days'));
        if ($days < 1) {
            $this->error('The retention period must be at least one day.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $pruned = 0;

        // Query-builder updates on purpose: this is the one sanctioned way precise
        // evidence is removed, and it bypasses the model's append-only guard.
        DB::table('employees_attendance_verifications')
            ->where('server_recorded_at', '<', $cutoff)
            ->where(fn ($query) => $query->whereNotNull('latitude')->orWhereNotNull('longitude')->orWhereNotNull('ip_address')->orWhereNotNull('user_agent'))
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$pruned): void {
                $pruned += DB::table('employees_attendance_verifications')
                    ->whereIn('id', $rows->pluck('id'))
                    ->update(['latitude' => null, 'longitude' => null, 'ip_address' => null, 'user_agent' => null]);
            });

        $this->info("Pruned precise-location evidence from {$pruned} verification(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
