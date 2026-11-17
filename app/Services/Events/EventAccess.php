<?php

namespace App\Services\Events;

use App\Models\Event;
use App\Models\User;
use App\Services\OrganizationContext;
use App\Support\Lookup;
use Illuminate\Database\Eloquent\Builder;

/** Who sees and runs an event. Events are open to the organization once published; drafts belong to their organizer. */
class EventAccess
{
    public function __construct(private OrganizationContext $ctx) {}

    public function can(string $permission): bool { return $this->ctx->can($permission); }

    public function manages(User $u, Event $e): bool { return $e->organizer_id === $u->id || $this->can('event.manage_all'); }

    public function requireManage(User $u, Event $e): void { abort_unless($this->manages($u, $e), 403, 'forbidden'); }

    public function scopeVisible(Builder $q, User $u): Builder
    {
        if ($this->can('event.manage_all')) {
            return $q;
        }
        return $q->where(fn ($w) => $w->where('organizer_id', $u->id)->orWhere('status_id', '!=', Lookup::id('event_status', 'draft')));
    }

    /** @param string[] $with */
    public function find(User $u, string $id, array $with = []): Event
    {
        $e = $this->scopeVisible(Event::query(), $u)->with($with)->whereKey($id)->first();
        abort_unless($e, 404);
        return $e;
    }
}
