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
