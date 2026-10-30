<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\ListExport;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\OrganizationContext;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Bundle 7 - reports. View and export are separate permissions (report.view / report.export) and every
 * report additionally needs its own module permission (see ReportService::definitions()).
 * Viewing and exporting are written to the audit log with the filters used (developer guide 13.2).
 */
class ReportController extends Controller
{
    public function __construct(private ReportService $reports, private OrganizationContext $ctx) {}

    public function index()
    {
        return response()->json(['data' => $this->reports->catalogue()]);
    }

    public function show(Request $request, string $code)
    {
        $data = $this->build($request, $code);
        AuditLogger::log('report.viewed', null, [], ['report' => $code, 'from' => $data['meta']['from'], 'to' => $data['meta']['to']]);
        return response()->json(['data' => $data]);
    }

    public function export(Request $request, string $code)
    {
        $format = $request->query('format', 'csv');
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 422, __('api.error'));

        $data = $this->build($request, $code);
        $keys = array_column($data['table']['columns'], 'key');
        $headings = array_column($data['table']['columns'], 'label');
        $rows = array_map(fn (array $r) => array_map(fn ($k) => self::safeCell($r[$k] ?? ''), $keys), $data['table']['rows']);

        AuditLogger::log('report.exported', null, [], ['report' => $code, 'from' => $data['meta']['from'], 'to' => $data['meta']['to'], 'format' => $format, 'rows' => count($rows)]);

        return Excel::download(new ListExport($rows, $headings), "report-{$code}-{$data['meta']['from']}_{$data['meta']['to']}.{$format}");
    }

    private function build(Request $request, string $code): array
    {
        abort_unless($this->reports->exists($code), 404);
        abort_unless($this->reports->allowed($code), 403);

        $d = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
        ]);

        return $this->reports->run($code, $d['from'] ?? null, $d['to'] ?? null);
    }

    /** Spreadsheet formula injection: a cell that starts with = + - @ (or a control character) is prefixed with an apostrophe. */
    private static function safeCell(mixed $v): mixed
    {
        if (!is_string($v) || $v === '') return $v;
        return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
    }
}
