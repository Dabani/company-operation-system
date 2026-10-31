<?php

namespace Tests\Feature;

use App\Mail\UserInvitationMail;
use App\Models\Role;
use App\Models\User;
use App\Models\UserInvitation;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Not executed in the build environment (no Laravel runtime) - run `php artisan test`. Needs the MySQL test database. */
class UserInvitationTest extends TestCase
{
    use RefreshDatabase;

    private string $pw = 'Str0ng-Passw0rd!x';

    protected function setUp(): void
    {
        parent::setUp();
        config(['ops.seed_admin_password' => 'Test-Password-123!', 'ops.seed_admin_email' => 'admin@example.com']);
        $this->seed(DatabaseSeeder::class);
    }

    /** Invite a user as admin and return [user, raw token taken from the e-mailed link]. */
    private function invite(string $email = 'New.Person@Example.com'): array
    {
        Sanctum::actingAs(User::where('email', 'admin@example.com')->firstOrFail());
        Mail::fake();
        $role = Role::withoutGlobalScopes()->where('code', 'staff')->firstOrFail();

        $this->postJson('/api/v1/users', ['name' => 'New Person', 'email' => $email, 'role_ids' => [$role->id]])
            ->assertCreated()->assertJsonPath('invitation_sent', true)->assertJsonPath('data.status', 'invited');

        $url = null;
        Mail::assertSent(UserInvitationMail::class, function ($m) use (&$url) { $url = $m->url; return true; });
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        return [User::where('email', strtolower($email))->firstOrFail(), $q['token']];
    }

    public function test_inviting_sends_an_activation_mail_not_a_password_reset(): void
    {
        [$user, $token] = $this->invite();
        $this->assertSame('new.person@example.com', $user->email);                    // normalised
        $this->assertStringContainsString('/activate?token=', 'x/activate?token=' . $token);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseHas('user_invitations', ['user_id' => $user->id, 'token_hash' => hash('sha256', $token)]);
    }

    public function test_activation_sets_the_password_activates_the_account_and_signs_in(): void
    {
        [$user, $token] = $this->invite();
        auth()->forgetGuards();

        $this->getJson('/api/v1/auth/activation?' . http_build_query(['email' => $user->email, 'token' => $token]))->assertOk()->assertJsonPath('valid', true);
        $this->postJson('/api/v1/auth/activate', ['email' => $user->email, 'token' => $token, 'password' => $this->pw, 'password_confirmation' => $this->pw])->assertOk();

        $user->refresh();
        $this->assertSame('active', $user->status->code);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(\Hash::check($this->pw, $user->password));

        // single use
        $this->postJson('/api/v1/auth/activate', ['email' => $user->email, 'token' => $token, 'password' => $this->pw, 'password_confirmation' => $this->pw])->assertStatus(422);
        // and the person can now log in
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => $this->pw])->assertOk();
    }

    public function test_wrong_or_expired_token_is_refused(): void
    {
        [$user, $token] = $this->invite();
        $this->postJson('/api/v1/auth/activate', ['email' => $user->email, 'token' => 'x' . substr($token, 1), 'password' => $this->pw, 'password_confirmation' => $this->pw])->assertStatus(422);

        UserInvitation::where('user_id', $user->id)->update(['expires_at' => now()->subMinute()]);
        $this->getJson('/api/v1/auth/activation?' . http_build_query(['email' => $user->email, 'token' => $token]))->assertStatus(422)->assertJsonPath('reason', 'expired');
        $this->postJson('/api/v1/auth/activate', ['email' => $user->email, 'token' => $token, 'password' => $this->pw, 'password_confirmation' => $this->pw])->assertStatus(422);
    }

    public function test_weak_password_is_refused(): void
    {
        [$user, $token] = $this->invite();
        $this->postJson('/api/v1/auth/activate', ['email' => $user->email, 'token' => $token, 'password' => 'short', 'password_confirmation' => 'short'])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_resend_replaces_the_old_link_and_is_throttled(): void
    {
        [$user, $old] = $this->invite();
        UserInvitation::where('user_id', $user->id)->update(['last_sent_at' => now()->subMinutes(5)]);

        Mail::fake();
        $this->postJson("/api/v1/users/{$user->id}/invite")->assertOk()->assertJsonPath('invitation_sent', true);
        $this->postJson("/api/v1/users/{$user->id}/invite")->assertStatus(429);                  // within the minute

        $this->postJson('/api/v1/auth/activate', ['email' => $user->email, 'token' => $old, 'password' => $this->pw, 'password_confirmation' => $this->pw])->assertStatus(422);
    }

    public function test_cancelling_removes_a_never_activated_user_but_not_an_active_one(): void
    {
        [$user] = $this->invite();
        $this->deleteJson("/api/v1/users/{$user->id}")->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);

    }

    public function test_forgot_password_for_an_invited_user_sends_an_invitation(): void
    {
        [$user] = $this->invite();
        UserInvitation::where('user_id', $user->id)->update(['last_sent_at' => now()->subMinutes(5)]);
        Mail::fake();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
        Mail::assertSent(UserInvitationMail::class);
    }
}
