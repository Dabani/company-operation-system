<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Support\Lookup;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    /** Fields never written to the audit trail. */
    protected const REDACT = ['password', 'remember_token', 'token'];

    public static function log(string $action, ?Model $entity = null, array $old = [], array $new = [], ?string $reason = null, string $outcome = 'success'): AuditLog
    {
        $ctx = app(OrganizationContext::class);
        $req = request();

        $log = AuditLog::create([
            'organization_id' => $ctx->organizationId() ?? ($entity->organization_id ?? null),
            'user_id' => optional($req->user())->id,
            'action' => $action,
            'entity_type' => $entity ? class_basename($entity) : null,
            'entity_id' => $entity?->getKey(),
            'outcome_id' => Lookup::id('audit_outcome', $outcome),
            'request_id' => $req->attributes->get('request_id'),
            'ip_address' => $req->ip(),
            'user_agent' => mb_substr((string) $req->userAgent(), 0, 250),
            'reason' => $reason,
        ]);

        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $field) {
            $o = $old[$field] ?? null;
            $n = $new[$field] ?? null;
            if ($o === $n) {
                continue;
            }
            $redacted = in_array($field, self::REDACT, true);
            $log->changes()->create([
                'field' => $field,
                'old_value' => $redacted ? '[redacted]' : (is_scalar($o) || $o === null ? $o : json_encode($o)),
                'new_value' => $redacted ? '[redacted]' : (is_scalar($n) || $n === null ? $n : json_encode($n)),
            ]);
        }

        return $log;
    }
}
