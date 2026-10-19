<?php

namespace App\Console\Commands;

use App\Models\FileAsset;
use App\Services\FileService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PurgeDeletedFiles extends Command
{
    protected $signature = 'files:purge-deleted';
    protected $description = 'Physically remove the content of files deleted longer ago than the retention window (metadata and audit trail are kept).';

    public function handle(): int
    {
        $cutoff = now()->subDays(config('ops.files.purge_after_days'));
        $n = 0;

        FileAsset::withoutGlobalScopes()->onlyTrashed()->where('deleted_at', '<', $cutoff)->with(['versions'])->chunkById(100, function ($files) use (&$n) {
            foreach ($files as $f) {
                foreach ($f->versions->whereNull('purged_at') as $v) {
                    Storage::disk(FileService::disk())->delete($v->storage_path);
                    $v->update(['purged_at' => now()]);
                    $n++;
                }
            }
        });

        $this->info("Purged {$n} file version(s).");
        return self::SUCCESS;
    }
}
