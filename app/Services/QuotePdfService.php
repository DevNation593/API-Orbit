<?php

namespace App\Services;

use App\Models\FileRecord;
use App\Models\Quote;
use App\Support\AuditService;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\File;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

final class QuotePdfService
{
    public function __construct(
        private readonly FilesystemManager $storage,
        private readonly AuditService $audit,
    ) {}

    public function generate(Quote $quote): FileRecord
    {
        $quote->loadMissing([
            'tenant:id,name', 'contact:id,first_name,last_name,email,phone', 'organization:id,name,legal_name,email,phone',
            'currency:id,code,name,symbol,decimal_places', 'items.taxes', 'items.discounts', 'discounts',
        ]);
        $temp = storage_path('app/tmp/mpdf');
        File::ensureDirectoryExists($temp);
        $pdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'tempDir' => $temp, 'default_font' => 'dejavusans']);
        $pdf->SetTitle('Cotización '.$quote->number.' v'.$quote->version);
        $pdf->SetAuthor($quote->tenant->name);
        $pdf->WriteHTML(view('pdf.quote', ['quote' => $quote])->render());
        $bytes = $pdf->Output('', Destination::STRING_RETURN);
        $disk = (string) config('filesystems.default', 'local');
        $path = 'tenants/'.$quote->tenant_id.'/quotes/'.$quote->public_id.'-v'.$quote->version.'.pdf';
        $this->storage->disk($disk)->put($path, $bytes, ['visibility' => 'private']);
        $safeNumber = preg_replace('/[^A-Za-z0-9._-]/', '-', $quote->number) ?: 'quote';
        $file = FileRecord::create([
            'disk' => $disk, 'path' => $path, 'filename' => $safeNumber.'-v'.$quote->version.'.pdf',
            'mime_type' => 'application/pdf', 'size' => strlen($bytes), 'uploaded_by' => auth()->id(),
            'related_type' => 'quote', 'related_id' => $quote->id,
            'metadata' => ['document_type' => 'quote', 'quote_number' => $quote->number, 'version' => $quote->version],
        ]);
        $quote->update(['pdf_file_id' => $file->id]);
        $this->audit->record('quote_pdf_generated', $quote, newValues: ['file_id' => $file->id, 'size' => $file->size]);

        return $file;
    }
}
