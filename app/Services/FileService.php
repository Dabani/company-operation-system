<?php

namespace App\Services;

use App\Models\FileAsset;
use App\Models\FileVersion;
use App\Models\User;
use App\Support\Lookup;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Single entry point for writing file content. Nothing else may touch the storage disk for uploads. */
class FileService
{
    /** Detected content types that are never accepted, whatever the extension says. */
    private const BLOCKED_MIME = [
        'application/x-dosexec', 'application/x-executable', 'application/x-msdownload', 'application/x-sh', 'application/x-httpd-php',
        'text/x-php', 'text/html', 'application/javascript', 'text/javascript', 'image/svg+xml', 'application/x-bat',
    ];

    public static function disk(): string { return config('ops.files.disk', 'local'); }

    /** @return array{0:string,1:string} [extension, detected mime] */
    public static function validate(UploadedFile $file): array
    {
        $fail = fn (string $m) => throw ValidationException::withMessages(['file' => [$m]]);

        if (!$file->isValid()) $fail(__('api.file_upload_failed'));
        if ($file->getSize() > config('ops.files.max_mb') * 1024 * 1024) $fail(__('api.file_too_large', ['mb' => config('ops.files.max_mb')]));

        $ext = strtolower($file->getClientOriginalExtension());
        if (!in_array($ext, config('ops.files.allowed_extensions'), true)) $fail(__('api.file_type_not_allowed'));

        $mime = (string) $file->getMimeType();                    // content-based detection, not the client header
        if (in_array($mime, self::BLOCKED_MIME, true)) $fail(__('api.file_type_not_allowed'));
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true) && !str_starts_with($mime, 'image/')) $fail(__('api.file_type_mismatch'));
        if ($ext === 'pdf' && $mime !== 'application/pdf') $fail(__('api.file_type_mismatch'));

        if (!app(FileScanner::class)->isClean($file->getRealPath())) $fail(__('api.file_infected'));

        return [$ext, $mime];
    }

    /** Create a new logical file with version 1. $link = ['type' => 'case', 'id' => ...]. */
    public static function store(UploadedFile $file, User $owner, array $attrs, ?array $link = null): FileAsset
    {
        [$ext, $mime] = self::validate($file);
        $orgId = app(OrganizationContext::class)->organizationId();

        return DB::transaction(function () use ($file, $owner, $attrs, $link, $ext, $mime, $orgId) {
            $asset = FileAsset::create([
                'name' => $attrs['name'] ?? $file->getClientOriginalName(),
                'description' => $attrs['description'] ?? null,
                'owner_id' => $owner->id,
                'classification_id' => $attrs['classification_id'] ?? Lookup::id('file_classification', 'internal'),
                'status_id' => Lookup::id('file_status', 'active'),
                'extension' => $ext, 'mime_type' => $mime, 'size_bytes' => $file->getSize(), 'version_count' => 1,
            ]);
            $v = self::writeVersion($asset, $file, $owner, 1, $ext, $mime, $orgId);
            $asset->update(['current_version_id' => $v->id]);

            if ($link) {
                $asset->links()->create(['link_type_id' => Lookup::id('file_link_type', $link['type']), 'link_id' => $link['id']]);
            }
            return $asset->load('currentVersion');
        });
    }

    /** Replace = new immutable version; older versions stay downloadable. */
    public static function addVersion(FileAsset $asset, UploadedFile $file, User $actor): FileVersion
    {
        [$ext, $mime] = self::validate($file);
        $orgId = app(OrganizationContext::class)->organizationId();

        return DB::transaction(function () use ($asset, $file, $actor, $ext, $mime, $orgId) {
            $n = (int) $asset->versions()->max('version_number') + 1;
            $v = self::writeVersion($asset, $file, $actor, $n, $ext, $mime, $orgId);
            $asset->update(['current_version_id' => $v->id, 'extension' => $ext, 'mime_type' => $mime, 'size_bytes' => $file->getSize(), 'version_count' => $n]);
            return $v;
        });
    }

    /** Single-purpose images (profile photo, organization logo): one asset per target, new uploads become new versions. */
    public static function storeSingle(UploadedFile $file, User $owner, string $linkType, string $linkId, string $classification = 'public'): FileAsset
    {
        $existing = FileAsset::whereHas('links', fn ($q) => $q->where('link_type_id', Lookup::id('file_link_type', $linkType))->where('link_id', $linkId))->first();
        if ($existing) {
            self::addVersion($existing, $file, $owner);
            return $existing->refresh()->load('currentVersion');
        }
        return self::store($file, $owner, ['classification_id' => Lookup::id('file_classification', $classification)], ['type' => $linkType, 'id' => $linkId]);
    }

    private static function writeVersion(FileAsset $asset, UploadedFile $file, User $by, int $n, string $ext, string $mime, string $orgId): FileVersion
    {
        $path = $file->storeAs("files/{$orgId}/" . now()->format('Y/m'), Str::uuid()->toString() . '.bin', self::disk());
        return FileVersion::create([
            'file_id' => $asset->id, 'version_number' => $n, 'original_name' => mb_substr($file->getClientOriginalName(), 0, 250),
            'storage_path' => $path, 'mime_type' => $mime, 'extension' => $ext, 'size_bytes' => $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()), 'uploaded_by' => $by->id,
        ]);
    }

    public static function exists(FileVersion $v): bool
    {
        return !$v->purged_at && Storage::disk(self::disk())->exists($v->storage_path);
    }
}
