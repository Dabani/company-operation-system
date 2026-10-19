<?php

namespace App\Imports;

use App\Models\Asset;
use App\Models\Department;
use App\Models\Location;
use App\Models\LookupValue;
use App\Services\AssetLog;
use App\Services\OrganizationContext;
use App\Services\References;
use App\Support\Lookup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/** Columns: name, type_code, manufacturer, model, serial_number, purchase_date, condition_code, department_code, location_code, notes */
class AssetsImport implements ToCollection, WithHeadingRow
{
    public int $created = 0;
    public array $errors = [];

    public function __construct(private OrganizationContext $ctx) {}

    private function lookupId(string $type, ?string $code): ?string
    {
        if (!$code) return null;
        return LookupValue::visible()->where('code', $code)->whereHas('type', fn ($q) => $q->where('code', $type))->value('id');
    }

    public function collection(Collection $rows): void
    {
        foreach ($rows as $i => $row) {
            $line = $i + 2;
            $v = Validator::make($row->toArray(), ['name' => 'required|string|max:200', 'type_code' => 'required|string', 'purchase_date' => 'nullable|date']);
            if ($v->fails()) { $this->errors[] = ['line' => $line, 'message' => $v->errors()->first()]; continue; }

            $type = $this->lookupId('asset_type', $row['type_code']);
            if (!$type) { $this->errors[] = ['line' => $line, 'message' => 'type_code']; continue; }
            $cond = $this->lookupId('asset_condition', $row['condition_code'] ?? null) ?? Lookup::id('asset_condition', 'good');
            if (!empty($row['serial_number']) && Asset::where('serial_number', $row['serial_number'])->exists()) { $this->errors[] = ['line' => $line, 'message' => 'serial_number']; continue; }

            DB::transaction(function () use ($row, $type, $cond) {
                $a = Asset::create([
                    'asset_tag' => References::next($this->ctx->organizationId(), 'asset', config('ops.asset_prefix'), false),
                    'type_id' => $type, 'status_id' => Lookup::id('asset_status', 'in_stock'), 'condition_id' => $cond,
                    'name' => $row['name'], 'manufacturer' => $row['manufacturer'] ?? null, 'model' => $row['model'] ?? null, 'serial_number' => $row['serial_number'] ?: null,
                    'purchase_date' => !empty($row['purchase_date']) ? $row['purchase_date'] : null,
                    'department_id' => !empty($row['department_code']) ? Department::where('code', $row['department_code'])->value('id') : null,
                    'location_id' => !empty($row['location_code']) ? Location::where('code', $row['location_code'])->value('id') : null,
                    'notes' => $row['notes'] ?? null,
                ]);
                AssetLog::event($a, 'registered', null, null, 'import');
            });
            $this->created++;
        }
    }
}
