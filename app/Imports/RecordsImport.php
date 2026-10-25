<?php

namespace App\Imports;

use App\Models\ArchiveRecord;
use App\Models\Department;
use App\Models\Location;
use App\Models\LookupValue;
use App\Services\OrganizationContext;
use App\Services\RecordRules;
use App\Services\References;
use App\Support\Lookup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/** Columns: title, category_code, format_code, department_code, record_date, client_name, project_name, location_code, status_code, classification_code */
class RecordsImport implements ToCollection, WithHeadingRow
{
    public int $created = 0;
    public array $errors = [];

    public function __construct(private OrganizationContext $ctx) {}

    private function lid(string $type, ?string $code): ?string
    {
        return $code ? LookupValue::visible()->where('code', $code)->whereHas('type', fn ($q) => $q->where('code', $type))->value('id') : null;
    }

    public function collection(Collection $rows): void
    {
        foreach ($rows as $i => $row) {
            $line = $i + 2;
            $v = Validator::make($row->toArray(), ['title' => 'required|string|max:250', 'category_code' => 'required', 'format_code' => 'required', 'record_date' => 'required|date']);
            if ($v->fails()) { $this->errors[] = ['line' => $line, 'message' => $v->errors()->first()]; continue; }

            $cat = $this->lid('record_category', $row['category_code']); $fmt = $this->lid('record_format', $row['format_code']);
            $status = $this->lid('record_status', $row['status_code'] ?? 'active') ?? Lookup::id('record_status', 'active');
            if (!$cat || !$fmt) { $this->errors[] = ['line' => $line, 'message' => 'category_code / format_code']; continue; }
            if (in_array(LookupValue::find($status)->code, ['disposal_pending', 'disposed'], true)) { $this->errors[] = ['line' => $line, 'message' => 'status_code']; continue; }

            $dept = !empty($row['department_code']) ? Department::where('code', $row['department_code'])->first() : null;
            $date = Carbon::parse($row['record_date']);

            DB::transaction(function () use ($row, $cat, $fmt, $status, $dept, $date) {
                $r = new ArchiveRecord([
                    'reference' => References::next($this->ctx->organizationId(), 'record', 'REC', true), 'title' => $row['title'],
                    'format_id' => $fmt, 'category_id' => $cat, 'status_id' => $status, 'classification_id' => $this->lid('file_classification', $row['classification_code'] ?? 'internal') ?? Lookup::id('file_classification', 'internal'),
                    'department_id' => $dept?->id, 'location_id' => !empty($row['location_code']) ? Location::where('code', $row['location_code'])->value('id') : null,
                    'record_date' => $date->toDateString(), 'client_name' => $row['client_name'] ?? null, 'project_name' => $row['project_name'] ?? null,
                    'index_code' => RecordRules::indexCode($dept?->code, LookupValue::find($cat)->code, $date), 'created_by' => request()->user()->id,
                ]);
                $r->organization_id = $this->ctx->organizationId();
                $code = LookupValue::find($status)->code;
                if ($code !== 'active') $r->became_inactive_on = $date->toDateString();
                if ($code === 'archived') $r->archived_on = $date->toDateString();
                $r->setRelation('status', LookupValue::find($status)); $r->setRelation('format', LookupValue::find($fmt));
                RecordRules::recompute($r);
                $r->save();
                RecordRules::event($r, 'created', null, null, 'import');
            });
            $this->created++;
        }
    }
}
