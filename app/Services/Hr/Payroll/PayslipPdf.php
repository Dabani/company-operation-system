<?php

namespace App\Services\Hr\Payroll;

use App\Models\Organization;
use App\Models\PayPayslip;
use App\Services\OrganizationContext;
use Dompdf\Dompdf;

/** Payslip as a PDF in the reader's language, from the STORED payslip. Same engine and fonts as the invoice and offer PDFs. */
class PayslipPdf
{
    public function render(PayPayslip $p, string $locale): string
    {
        $org = app(OrganizationContext::class)->organization() ?? Organization::findOrFail($p->organization_id);
        $html = view('pdf.payslip', ['slip' => PayslipPresenter::detail($p, $locale), 'org' => $org, 'locale' => $locale])->render();
        $pdf = new Dompdf(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false]);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        return $pdf->output();
    }
}
