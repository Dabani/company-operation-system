<?php

use App\Models\ChatConversation;
use App\Services\OrganizationContext;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('users.{id}', fn ($user, $id) => (string) $user->id === (string) $id);

// Requester of the conversation, or an agent of the same organization.
Broadcast::channel('chat.{id}', function ($user, $id) {
    $c = ChatConversation::withoutGlobalScopes()->find($id);
    if (!$c) return false;
    if ($c->user_id === $user->id) return true;
    $ctx = new OrganizationContext();
    return $ctx->resolve($user, $c->organization_id) && $ctx->can('chat.moderate');
});

Broadcast::channel('org.{orgId}.agents', function ($user, $orgId) {
    $ctx = new OrganizationContext();
    return $ctx->resolve($user, $orgId) && $ctx->can('chat.moderate');
});
