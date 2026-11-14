<?php

namespace Tests\Feature;

use App\Http\Controllers\UserController;
use App\Models\User;
use App\Notifications\AccountCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Concerns\BuildsFixtures;
use Tests\TestCase;

/** New users (and admin password resets) get their sign-in details by e-mail and must change the temporary password at first sign-in. */
class UserCredentialsEmailTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures();
    }

    private function create(array $over = [])
    {
        return $this->actingAs($this->admin)->post(route('users.store'), $over + [
            'name' => 'Brian Okello', 'email' => 'brian@example.test', 'role' => 'accountant', 'is_active' => 1,
        ]);
    }

    public function test_new_user_is_emailed_a_generated_temporary_password_that_works_once(): void
    {
        Notification::fake();
        $this->create()->assertRedirect(route('users.index'))->assertSessionHas('status', fn ($s) => str_contains($s, 'e-mailed to brian@example.test'));
        $brian = User::where('email', 'brian@example.test')->sole();
        $this->assertTrue($brian->must_change_password);

        $sent = null;
        Notification::assertSentTo($brian, AccountCredentials::class, function ($n) use (&$sent) {
            $sent = $n->temporaryPassword;

            return ! $n->isReset;
        });
        $this->assertMatchesRegularExpression('/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)[A-Za-z\d]{12}$/', $sent);
        $this->assertTrue(Hash::check($sent, $brian->password));

        $mail = (new AccountCredentials($sent))->toMail($brian)->render();
        $this->assertStringContainsString($sent, (string) $mail);
        $this->assertStringContainsString('brian@example.test', (string) $mail);

        // First sign-in goes straight to "Change password"; after changing, the temporary password no longer works.
        auth()->logout();
        $this->post(route('login'), ['email' => 'brian@example.test', 'password' => $sent]);
        $this->get(route('dashboard'))->assertRedirect(route('password.change'));
        $this->put(route('password.change.update'), ['current_password' => $sent, 'password' => 'MyOwnPass99', 'password_confirmation' => 'MyOwnPass99'])
            ->assertRedirect(route('dashboard'));
        $this->assertFalse($brian->fresh()->must_change_password);
        $this->assertFalse(Hash::check($sent, $brian->fresh()->password));
    }

    public function test_a_typed_temporary_password_is_used_and_emailed(): void
    {
        Notification::fake();
        $this->create(['password' => 'Welcome2026'])->assertSessionHasNoErrors();
        $brian = User::where('email', 'brian@example.test')->sole();
        Notification::assertSentTo($brian, AccountCredentials::class, fn ($n) => $n->temporaryPassword === 'Welcome2026');
        $this->assertTrue(Hash::check('Welcome2026', $brian->password));
    }

    public function test_admin_reset_generates_and_emails_a_new_temporary_password(): void
    {
        Notification::fake();
        $this->actingAs($this->admin)->put(route('users.update', $this->accountant), [
            'name' => $this->accountant->name, 'email' => $this->accountant->email, 'role' => 'accountant', 'is_active' => 1, 'reset_password' => 1,
        ])->assertRedirect(route('users.index'));
        $this->assertTrue($this->accountant->fresh()->must_change_password);
        Notification::assertSentTo($this->accountant, AccountCredentials::class,
            fn ($n) => $n->isReset && Hash::check($n->temporaryPassword, $this->accountant->fresh()->password));

        // A plain edit (no reset) sends nothing.
        Notification::fake();
        $this->actingAs($this->admin)->put(route('users.update', $this->accountant), [
            'name' => 'Renamed', 'email' => $this->accountant->email, 'role' => 'accountant', 'is_active' => 1,
        ]);
        Notification::assertNothingSent();
    }

    public function test_when_mail_fails_the_user_is_still_created_and_admin_sees_the_password_once(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 1]);
        $this->create()->assertRedirect(route('users.index'))->assertSessionHas('credentials', fn ($c) => $c['email'] === 'brian@example.test'
            && Hash::check($c['password'], User::where('email', 'brian@example.test')->value('password')));
        $this->assertTrue(User::where('email', 'brian@example.test')->exists());
    }

    public function test_generated_passwords_meet_the_password_rule_and_differ(): void
    {
        $a = UserController::temporaryPassword();
        $b = UserController::temporaryPassword();
        $this->assertNotSame($a, $b);
        $this->assertDoesNotMatchRegularExpression('/[0O1lI]/', $a.$b, 'no look-alike characters');
    }
}
