<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\InvoiceLineSource;
use App\Models\Organization;
use App\Services\OrganizationContext;
use Dompdf\Dompdf;

/** Invoice PDF with a time-breakdown annex, rendered from the stored snapshot (never from live rates), so it is identical years later. */
class InvoicePdf
{
    public function render(Invoice $invoice): string
    {
        $i = Invoice::with(['lines.kind', 'lines.sources.timeEntry.user:id,name', 'lines.sources.timeEntry.task:id,number,title', 'status', 'project:id,code,name', 'payments', 'creditNotes.status'])->findOrFail($invoice->id);
        $org = app(OrganizationContext::class)->organization() ?? Organization::findOrFail($i->organization_id);
        $annex = $i->lines->flatMap(fn ($l) => $l->sources->map(fn (InvoiceLineSource $s) => [
            'date' => $s->timeEntry->work_date->toDateString(), 'person' => $s->timeEntry->user?->name,
            'task' => $s->timeEntry->task ? "#{$s->timeEntry->task->number} {$s->timeEntry->task->title}" : '-', 'hours' => number_format($s->seconds / 3600, 2, '.', ''),
        ]))->sortBy('date')->values();

        $html = view('pdf.invoice', ['i' => $i, 'org' => $org, 'annex' => $annex, 'draft' => $i->number === null])->render();
        $pdf = new Dompdf(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false]);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        return $pdf->output();
    }
}
