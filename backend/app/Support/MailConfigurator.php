<?php

namespace App\Support;

use App\Models\MailSetting;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;

/**
 * Points the mailer at whatever the admin last saved in the panel.
 *
 * Laravel reads mail config once at boot from .env. The panel writes to the
 * database, so without this the settings page would look like it worked and
 * change nothing at all.
 */
class MailConfigurator
{
    /**
     * Applies the saved settings, and says whether there were any.
     *
     * Returns false when the shop has not filled them in, so a caller can
     * say "no mail server configured" rather than letting the mailer fail
     * with something about a missing host.
     */
    public static function apply(): bool
    {
        $settings = MailSetting::query()->first();

        if (! $settings || ! $settings->is_configured) {
            return false;
        }

        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp.host', $settings->host);
        Config::set('mail.mailers.smtp.port', $settings->port);
        Config::set('mail.mailers.smtp.username', $settings->username);
        Config::set('mail.mailers.smtp.password', $settings->password);

        // Laravel 11 reads 'scheme'; the older 'encryption' key is kept
        // because the shop's .env still carries one and a half-migrated
        // install should not silently drop TLS.
        Config::set('mail.mailers.smtp.scheme', $settings->encryption === 'ssl' ? 'smtps' : null);
        Config::set('mail.mailers.smtp.encryption', $settings->encryption);

        Config::set('mail.from.address', $settings->from_address);
        Config::set('mail.from.name', $settings->from_name ?: config('app.name'));

        // The mailer caches resolved transports, so a second send in the
        // same process would otherwise keep using the old credentials.
        Mail::purge('smtp');

        return true;
    }
}
