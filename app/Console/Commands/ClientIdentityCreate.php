<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\ClientIdentity;
use App\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ClientIdentityCreate extends Command
{
    protected $signature = 'cops:client-identity {organization : organization slug} {client : client code} {email}';
    protected $description = 'Create a portal login for a client and print a one-time password (invitations arrive with Phase 3)';

    public function handle(): int
    {
        $org = Organization::where('slug', $this->argument('organization'))->firstOrFail();
        $client = Client::withoutGlobalScopes()->where('organization_id', $org->id)->where('code', $this->argument('client'))->firstOrFail();
        $password = Str::password(16);
        ClientIdentity::withoutGlobalScopes()->create([
            'organization_id' => $org->id, 'client_id' => $client->id, 'email' => strtolower($this->argument('email')), 'password' => $password,
        ]);
        $this->info("Created. One-time password (shown once): $password");
        return self::SUCCESS;
    }
}
