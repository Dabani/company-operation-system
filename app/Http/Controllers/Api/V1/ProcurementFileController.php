<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\FileResource;
use App\Models\FileAsset;
use App\Models\LookupType;
use App\Models\RfqQuote;
use App\Models\SupplierInvoice;
use App\Services\AuditLogger;
use App\Services\FileAccess;
use App\Services\FileService;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Controlled Files attached to supplier quotes (RFQ) and supplier invoices. Same classification and access rules as every other file. */
class ProcurementFileController extends Controller
{
    private function list(string $type, string $id)
    {
        $q = FileAccess::scopeVisible(FileAsset::query())->with(['classification.translations', 'status.translations', 'owner'])->whereHas('status', fn ($s) => $s->where('code', 'active'))
            ->whereHas('links', fn ($l) => $l->where('link_type_id', Lookup::id('file_link_type', $type))->where('link_id', $id))->orderByDesc('created_at');
        return FileResource::collection($q->get());
    }

    private function store(Request $request, string $type, string $id, string $auditKey)
    {
        $d = $request->validate(['file' => 'required|file', 'classification_id' => ['nullable', Rule::exists('lookup_values', 'id')->where(fn ($q) => $q->whereIn('lookup_type_id', LookupType::where('code', 'file_classification')->pluck('id')))]]);
        abort_if(($d['classification_id'] ?? null) === Lookup::id('file_classification', 'restricted'), 422, __('procurement.file_not_restricted'));
        $asset = FileService::store($request->file('file'), $request->user(), $d, ['type' => $type, 'id' => $id])->load(['classification.translations', 'status.translations', 'owner']);
        AuditLogger::log('file.uploaded', $asset, [], ['name' => $asset->name, $auditKey => $id, 'sha256' => $asset->currentVersion->sha256]);
        return (new FileResource($asset))->response()->setStatusCode(201);
    }

    public function quoteFiles(string $id)
    {
        RfqQuote::findOrFail($id);
        return $this->list('rfq_quote', $id);
    }

    public function storeQuoteFile(Request $request, string $id)
    {
        $q = RfqQuote::with('rfq.status')->findOrFail($id);
        abort_unless(in_array($q->rfq->status->code, ['open', 'closed'], true), 403, 'forbidden');
        return $this->store($request, 'rfq_quote', $id, 'rfq_quote');
    }

    public function invoiceFiles(string $id)
    {
        SupplierInvoice::findOrFail($id);
        return $this->list('supplier_invoice', $id);
    }

    public function storeInvoiceFile(Request $request, string $id)
    {
        $i = SupplierInvoice::with('status')->findOrFail($id);
        abort_unless(in_array($i->status->code, ['received', 'on_hold', 'matched', 'approved'], true), 403, 'forbidden');
        return $this->store($request, 'supplier_invoice', $id, 'supplier_invoice');
    }
}
