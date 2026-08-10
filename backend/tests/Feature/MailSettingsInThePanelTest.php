<?php

namespace Tests\Feature;

use App\Filament\Pages\ManageMail;
use App\Models\MailSetting;
use App\Models\User;
use App\Support\MailConfigurator;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The mail server used to live in .env, so a credential that expired was
 * invisible to everyone who could not SSH into the box — and the nightly
 * backup stopped leaving the machine without a word.
 */
class MailSettingsInThePanelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    private function asAdminPanel(): void
    {
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_the_admin_can_open_the_mail_page(): void
    {
        $this->asAdminPanel();

        Livewire::test(ManageMail::class)->assertOk();
    }

    public function test_the_password_is_not_stored_in_the_clear(): void
    {
        // A settings row is read by more code than .env was, and it rides
        // along in every backup — including the ones copied onto a laptop.
        MailSetting::current()->update(['password' => 'hunter2-and-then-some']);

        $stored = DB::table('mail_settings')->value('password');

        $this->assertNotSame('hunter2-and-then-some', $stored);
        $this->assertStringNotContainsString('hunter2', (string) $stored);
        $this->assertSame('hunter2-and-then-some', MailSetting::current()->password);
    }

    public function test_settings_saved_in_the_panel_reach_the_mailer(): void
    {
        MailSetting::current()->update([
            'host' => 'smtp.example.test',
            'port' => 2525,
            'username' => 'shop',
            'password' => 'secret',
            'from_address' => 'shop@example.test',
            'from_name' => 'نانوایی',
        ]);

        $this->assertTrue(MailConfigurator::apply());

        // Laravel reads mail config once at boot, so without this the page
        // would look like it worked and change nothing.
        $this->assertSame('smtp.example.test', config('mail.mailers.smtp.host'));
        $this->assertSame(2525, config('mail.mailers.smtp.port'));
        $this->assertSame('secret', config('mail.mailers.smtp.password'));
        $this->assertSame('shop@example.test', config('mail.from.address'));
    }

    public function test_half_filled_settings_are_not_applied(): void
    {
        MailSetting::current()->update(['host' => 'smtp.example.test']);

        // Better to report "not configured" than to hand the mailer a
        // hostname and no credentials and let it fail obscurely.
        $this->assertFalse(MailConfigurator::apply());
    }

    public function test_recipients_are_split_and_cleaned(): void
    {
        MailSetting::current()->update([
            'backup_mail_to' => ' one@example.test , two@example.test ,, one@example.test , rubbish ',
        ]);

        $this->assertSame(
            ['one@example.test', 'two@example.test'],
            MailSetting::current()->recipients(),
        );
    }

    public function test_a_failed_test_keeps_the_reason(): void
    {
        MailSetting::current()->update([
            'last_tested_at' => now(),
            'last_test_succeeded' => false,
            'last_test_error' => '535 BadCredentials',
        ]);

        // The reason a send failed is the whole diagnosis, and a toast that
        // has faded tells nobody anything.
        $settings = MailSetting::current();

        $this->assertFalse($settings->last_test_succeeded);
        $this->assertStringContainsString('535', $settings->last_test_error);
    }

    public function test_the_backup_does_not_mail_while_the_panel_switch_is_off(): void
    {
        MailSetting::current()->update([
            'host' => 'smtp.example.test',
            'username' => 'shop',
            'password' => 'secret',
            'from_address' => 'shop@example.test',
            'backup_mail_to' => 'owner@example.test',
            'backup_mail_enabled' => false,
        ]);

        $this->artisan('backup:database', ['--keep' => 1])
            ->expectsOutputToContain('ارسال شبانه در پنل خاموش است')
            ->assertSuccessful();
    }
}
