<?php

namespace App\Services;

use App\Models\Setting;
use App\Support\Lookup;

/** Typed organization settings (no JSON): one typed column per value. */
class SettingsService
{
    /** key => [type, default]. Only whitelisted keys can be read or written. */
    public const KEYS = [
        'archive.transfer_days' => ['integer', 30],          // days after a record becomes inactive before it must reach the central archive
        'archive.default_retention_years' => ['integer', 10], // used when no retention rule exists for a category
        'training.retention_years' => ['integer', 3],         // how long training attendance evidence is kept
        'time.single_timer' => ['boolean', true],            // one running timer per person; starting another asks to stop the first
        'time.max_hours' => ['integer', 12],                 // a timer running longer is stopped automatically and flagged for correction
        'time.manual_allowed' => ['boolean', true],          // allow manual time entries (reason required)
        'time.manual_max_days_back' => ['integer', 31],      // how far back a manual entry may start
        'time.idle_warning_minutes' => ['integer', 30],      // the header timer warns after this long without activity
        'billing.rounding_minutes' => ['integer', 1],         // time on invoices is rounded once, to this increment (1 = to the minute)
        'billing.rounding_mode' => ['string', 'half_up'],       // half_up | up | down
        'billing.payment_terms_days' => ['integer', 30],       // default payment terms printed on invoices
        'portal.mfa_required' => ['boolean', true],           // client users must pass two-factor verification
        'portal.upload_max_mb' => ['integer', 10],            // client upload size limit (never above the global file limit)
        'portal.upload_extensions' => ['string', 'pdf,docx,xlsx,pptx,png,jpg,jpeg,txt,csv'],   // allowed client upload types (intersected with the global list)
        'meeting.reminder_offsets' => ['string', '1440,60'],     // minutes before the start at which invitees are reminded (comma separated, 5 to 20160)
        'meeting.reminder_sms' => ['boolean', false],            // also remind by SMS (in-app and e-mail always)
        'meeting.late_after_minutes' => ['integer', 10],         // arriving within this many minutes of the start still counts as present
        'meeting.attendance_ack' => ['boolean', false],          // participants confirm their own line of the attendance sheet
        'meeting.followup_due_soon_days' => ['integer', 2],      // resolutions and actions due within this many days are announced to their owner
        'meeting.followup_overdue_repeat_days' => ['integer', 3], // an overdue item is announced again every this many days
    ];

    public static function get(string $orgId, string $key)
    {
        [$type, $default] = self::KEYS[$key];
        $row = Setting::withoutGlobalScopes()->where('organization_id', $orgId)->where('key', $key)->first();
        if (!$row) return $default;
        return match ($type) { 'integer' => (int) $row->value_integer, 'boolean' => (bool) $row->value_boolean, 'decimal' => (float) $row->value_decimal, default => $row->value_text };
    }

    public static function set(string $orgId, string $key, $value): void
    {
        abort_unless(isset(self::KEYS[$key]), 422);
        [$type] = self::KEYS[$key];
        $col = ['integer' => 'value_integer', 'boolean' => 'value_boolean', 'decimal' => 'value_decimal', 'date' => 'value_date'][$type] ?? 'value_text';
        Setting::withoutGlobalScopes()->updateOrCreate(['organization_id' => $orgId, 'key' => $key], ['value_type_id' => Lookup::id('setting_value_type', $type === 'integer' ? 'integer' : 'string'), $col => $value]);
    }
}
