<?php

namespace Tests\Feature;

use App\Filament\Pages\ManageBale;
use App\Models\BaleSetting;
use App\Models\User;
use App\Support\BaleNotifier;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A third way for the nightly backup to leave the machine — see
 * TelegramSettingsInThePanelTest, which this mirrors. Bale's bot API is
 * deliberately Telegram-compatible, reachable where Telegram itself is not.
 */
class BaleSettingsInThePanelTest extends TestCase
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

    public function test_the_admin_can_open_the_bale_page(): void
    {
        $this->asAdminPanel();

        Livewire::test(ManageBale::class)->assertOk();
    }

    public function test_the_token_is_not_stored_in_the_clear(): void
    {
        BaleSetting::current()->update(['bot_token' => '123456:hunter2-and-then-some']);

        $stored = DB::table('bale_settings')->value('bot_token');

        $this->assertNotSame('123456:hunter2-and-then-some', $stored);
        $this->assertStringNotContainsString('hunter2', (string) $stored);
        $this->assertSame('123456:hunter2-and-then-some', BaleSetting::current()->bot_token);
    }

    public function test_half_filled_settings_are_not_configured(): void
    {
        BaleSetting::current()->update(['bot_token' => '123456:abcdef']);

        $this->assertFalse(BaleSetting::current()->is_configured);
    }

    public function test_a_failed_test_keeps_the_reason(): void
    {
        BaleSetting::current()->update([
            'last_tested_at' => now(),
            'last_test_succeeded' => false,
            'last_test_error' => 'error_code 401',
        ]);

        $settings = BaleSetting::current();

        $this->assertFalse($settings->last_test_succeeded);
        $this->assertStringContainsString('401', $settings->last_test_error);
    }

    public function test_a_configured_bot_can_send_a_message(): void
    {
        Http::fake(['tapi.bale.ai/*' => Http::response(['ok' => true, 'result' => []])]);

        $result = BaleNotifier::sendMessage('123456:abcdef', '999', 'سلام');

        $this->assertTrue($result['ok']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'bot123456:abcdef/sendMessage')
            && str_contains($request->body(), '999'));
    }

    public function test_a_failure_with_no_description_falls_back_to_the_error_code(): void
    {
        // Bale's own docs only promise an integer error_code on failure,
        // not the description string Telegram always sends alongside it.
        Http::fake(['tapi.bale.ai/*' => Http::response(['ok' => false, 'error_code' => 401], 401)]);

        $result = BaleNotifier::sendMessage('123456:abcdef', '999', 'سلام');

        $this->assertFalse($result['ok']);
        $this->assertSame('error_code 401', $result['error']);
    }

    public function test_bales_own_description_is_kept_as_the_error_when_present(): void
    {
        Http::fake(['tapi.bale.ai/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401)]);

        $result = BaleNotifier::sendMessage('123456:abcdef', '999', 'سلام');

        $this->assertFalse($result['ok']);
        $this->assertSame('Unauthorized', $result['error']);
    }

    public function test_the_backup_does_not_bale_while_the_panel_switch_is_off(): void
    {
        BaleSetting::current()->update([
            'bot_token' => '123456:abcdef',
            'chat_id' => '999',
            'backup_bale_enabled' => false,
        ]);

        $this->artisan('backup:database', ['--keep' => 1, '--no-mail' => true])
            ->expectsOutputToContain('ارسال بله در پنل خاموش است')
            ->assertSuccessful();
    }

    public function test_the_backup_warns_when_unconfigured(): void
    {
        $this->artisan('backup:database', ['--keep' => 1, '--no-mail' => true])
            ->expectsOutputToContain('بازوی بله تنظیم نشده')
            ->assertSuccessful();
    }

    public function test_the_backup_reaches_bale_when_enabled_and_configured(): void
    {
        Http::fake(['tapi.bale.ai/*' => Http::response(['ok' => true, 'result' => []])]);

        BaleSetting::current()->update([
            'bot_token' => '123456:abcdef',
            'chat_id' => '999',
            'backup_bale_enabled' => true,
        ]);

        $exit = Artisan::call('backup:database', ['--keep' => 1, '--no-mail' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('به بله ارسال شد', $output, "exit={$exit}\n{$output}");

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendDocument')
            && str_contains($request->body(), '999'));
    }
}
