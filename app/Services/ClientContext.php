<?php

namespace App\Services;

use App\Models\ClientIdentity;

/** Request-scoped context for the external portal. Active only when the "client" guard authenticated the request. */
class ClientContext
{
    protected ?ClientIdentity $identity = null;
    protected bool $active = false;

    public function activate(?ClientIdentity $identity): void { $this->active = true; $this->identity = $identity; }
    public function active(): bool { return $this->active; }
    public function identity(): ?ClientIdentity { return $this->identity; }
    public function clientId(): ?string { return $this->identity?->client_id; }
}
