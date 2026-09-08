<?php

namespace Webkul\Employee\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class AttendanceRecord extends Model
{
    protected $table = 'employees_attendance_records';

    protected $fillable = [
        'company_id',
        'employee_id',
        'approved_by',
        'creator_id',
        'attendance_date',
        'scheduled_start',
        'scheduled_end',
        'check_in',
        'check_out',
        'worked_hours',
        'overtime_hours',
        'late_minutes',
        'early_departure_minutes',
        'status',
        'source',
        'source_reference',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date'        => 'date',
            'scheduled_start'        => 'datetime',
            'scheduled_end'          => 'datetime',
            'check_in'               => 'datetime',
            'check_out'              => 'datetime',
            'worked_hours'           => 'decimal:4',
            'overtime_hours'         => 'decimal:4',
            'late_minutes'           => 'integer',
            'early_departure_minutes'=> 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    protected static function booted(): void
    {
        static::creating(function (self $record): void {
            $record->creator_id ??= Auth::id();
            $record->company_id ??= $record->employee?->company_id;
        });

        static::saving(function (self $record): void {
            // Each of these previously only reassigned the field when BOTH sides
            // of its pair were present, with no else branch — so clearing just
            // one side on an edit (e.g. correcting a bad check_out) left the
            // stale value from the last time both were set, silently persisted
            // as if it still applied. Reset to the column's own default (0,
            // matching the migration — these are non-nullable unsigned columns)
            // whenever the pair is incomplete, so an edited-down record can't
            // keep claiming hours/lateness/early-departure it no longer has.
            if ($record->check_in && $record->check_out) {
                $record->worked_hours = max(0, $record->check_in->diffInMinutes($record->check_out) / 60);
            } else {
                $record->worked_hours = 0;
            }
            if ($record->scheduled_start && $record->check_in) {
                $record->late_minutes = max(0, $record->scheduled_start->diffInMinutes($record->check_in, false));
            } else {
                $record->late_minutes = 0;
            }
            if ($record->scheduled_end && $record->check_out) {
                $record->early_departure_minutes = max(0, $record->check_out->diffInMinutes($record->scheduled_end, false));
            } else {
                $record->early_departure_minutes = 0;
            }
        });
    }
}
