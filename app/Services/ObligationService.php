<?php

namespace App\Services;

use App\Models\ComplianceObligation;
use App\Models\ObligationCompletion;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** The compliance calendar engine: recurrence maths, completion, and automatic completion when the work is recorded elsewhere. */
class ObligationService
{
    public static function add(Carbon $d, string $frequency): Carbon
    {
        $d = $d->copy();
        return match ($frequency) {
            'weekly' => $d->addWeek(), 'monthly' => $d->addMonthNoOverflow(), 'quarterly' => $d->addMonthsNoOverflow(3),
            'semi_annual' => $d->addMonthsNoOverflow(6), default => $d->addYearNoOverflow(),
        };
    }

    /** How many days before the due date the work may already be recorded against this cycle. */
    public static function window(string $frequency): int
    {
        return ['weekly' => 6, 'monthly' => 15, 'quarterly' => 45, 'semi_annual' => 90, 'annual' => 90][$frequency] ?? 30;
    }

    public static function state(ComplianceObligation $o): string
    {
        if (!$o->is_active) return 'inactive';
        if ($o->next_due_on->lt(today())) return 'overdue';
        return $o->next_due_on->lte(today()->addDays(14)) ? 'due_soon' : 'ok';
    }

    public static function complete(ComplianceObligation $o, Carbon $on, ?User $by, ?string $notes = null, bool $automatic = false): ObligationCompletion
    {
        return DB::transaction(function () use ($o, $on, $by, $notes, $automatic) {
            $o->loadMissing('frequency');
            $c = ObligationCompletion::create(['obligation_id' => $o->id, 'due_on' => $o->next_due_on, 'completed_on' => $on, 'completed_by' => $by?->id, 'notes' => $notes ? mb_substr($notes, 0, 1000) : null, 'automatic' => $automatic]);

            $next = $o->next_due_on->copy();
            do { $next = self::add($next, $o->frequency->code); } while ($next->lte($on));      // next occurrence strictly after the completion date
            $o->update(['last_completed_on' => $on, 'next_due_on' => $next, 'alerted_for' => null]);
            return $c;
        });
    }

    /** Called by features (backup verification, inventory, training, audit). Completes the matching obligation only when its cycle is near. */
    public static function autoComplete(string $orgId, string $code, ?User $by, string $note): bool
    {
        $o = ComplianceObligation::withoutGlobalScopes()->with('frequency')->where('organization_id', $orgId)->where('code', $code)->where('is_active', true)->first();
        if (!$o || $o->next_due_on->gt(today()->addDays(self::window($o->frequency->code)))) return false;
        self::complete($o, today(), $by, $note, true);
        return true;
    }
}
