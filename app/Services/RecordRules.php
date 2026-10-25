<?php

namespace App\Services;

use App\Models\ArchiveRecord;
use App\Models\RecordEvent;
use App\Models\RetentionRule;
use App\Support\Lookup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Everything date- and code-related about a record lives here so the rules stay consistent. */
class RecordRules
{
    /** audit_working_paper -> AWP, contract -> CON */
    public static function abbrev(string $code): string
    {
        $parts = array_filter(explode('_', $code));
        return count($parts) > 1 ? strtoupper(implode('', array_map(fn ($p) => $p[0], $parts))) : strtoupper(substr($code, 0, 3));
    }

    /** Department code – category – YYYYMMDD */
    public static function indexCode(?string $departmentCode, string $categoryCode, Carbon $date): string
    {
        return strtoupper($departmentCode ?: 'GEN') . '-' . self::abbrev($categoryCode) . '-' . $date->format('Ymd');
    }

    /** ClientName_ProjectName_YYYYMMDD (digital naming convention). */
    public static function digitalName(ArchiveRecord $r): ?string
    {
        if (!$r->client_name || !$r->project_name) return null;
        $clean = fn ($s) => preg_replace('/[^A-Za-z0-9]+/', '', Str::ascii($s));
        return $clean($r->client_name) . '_' . $clean($r->project_name) . '_' . $r->record_date->format('Ymd');
    }

    /** @return array{0:int,1:?RetentionRule} years + the rule that decided it (organization rule beats shared default). */
    public static function retention(string $orgId, string $categoryId): array
    {
        $rule = RetentionRule::where('category_id', $categoryId)->where(fn ($q) => $q->where('organization_id', $orgId)->orWhereNull('organization_id'))
            ->orderByRaw('organization_id is null')->first();
        return [$rule?->retention_years ?? SettingsService::get($orgId, 'archive.default_retention_years'), $rule];
    }

    /** Sets retention_until and transfer_due_on from the record's current state. Does not save. */
    public static function recompute(ArchiveRecord $r): void
    {
        $status = $r->status?->code ?? optional(\App\Models\LookupValue::find($r->status_id))->code;
        $physical = in_array($r->format?->code ?? optional(\App\Models\LookupValue::find($r->format_id))->code, ['physical', 'hybrid'], true);

        if (in_array($status, ['inactive', 'archived', 'disposal_pending'], true)) {
            $start = $r->became_inactive_on ?? $r->record_date;                  // retention clock starts when the record stops being operational
            [$years] = self::retention($r->organization_id, $r->category_id);
            $r->retention_until = $start->copy()->addYears($years)->toDateString();
        } elseif ($status === 'active') {
            $r->retention_until = null;
        }

        $r->transfer_due_on = ($status === 'inactive' && $physical && $r->became_inactive_on)
            ? $r->became_inactive_on->copy()->addDays(SettingsService::get($r->organization_id, 'archive.transfer_days'))->toDateString()
            : null;
    }

    public static function event(ArchiveRecord $r, string $type, ?string $old = null, ?string $new = null, ?string $note = null): void
    {
        RecordEvent::create(['record_id' => $r->id, 'user_id' => optional(request()->user())->id, 'event_type_id' => Lookup::id('record_event_type', $type),
            'old_ref' => $old, 'new_ref' => $new, 'note' => $note ? mb_substr($note, 0, 250) : null]);
    }
}
