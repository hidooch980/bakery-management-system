<?php

namespace App\Console\Commands;

use App\Models\Bakery;
use App\Models\User;
use App\Support\CurrentBakery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Opens a second shop on the same installation.
 *
 * Deliberately not in the panel. An admin belongs to one bakery and sees
 * only theirs, which is what keeps two shops' takings apart — so nobody
 * inside the panel is in a position to create a third. Whoever runs the
 * server is, and this is how they do it.
 *
 * The new shop starts with its own admin and nothing else: no staff, no
 * stock, no history. Everything it records from here belongs to it alone.
 */
class CreateBakery extends Command
{
    protected $signature = 'bakery:create
        {name : The shop\'s name, as it appears in the panel}
        {--admin-name= : The first admin\'s name}
        {--admin-email= : The email they sign in with}
        {--admin-phone= : The phone they may sign in with instead}
        {--admin-password= : Their password; asked for if omitted}
        {--like= : Copy the formula, weights and prices from this bakery id}';

    protected $description = 'ساخت نانوایی جدید به همراه مدیر آن';

    public function handle(): int
    {
        $name = $this->argument('name');
        $adminName = $this->option('admin-name') ?: $this->ask('نام مدیر');
        $email = $this->option('admin-email') ?: $this->ask('ایمیل مدیر');
        $phone = $this->option('admin-phone') ?: $this->ask('شماره تلفن مدیر');

        $password = $this->option('admin-password') ?: $this->secret('رمز عبور مدیر');

        if (blank($password)) {
            $this->error('رمز عبور نمی‌تواند خالی باشد.');

            return self::FAILURE;
        }

        // Checked before anything is written: a login is unique across the
        // whole installation, because signing in names a person, not a shop.
        if (User::where('email', $email)->orWhere('phone', $phone)->exists()) {
            $this->error('کاربری با این ایمیل یا شماره تلفن از قبل وجود دارد.');

            return self::FAILURE;
        }

        $bakery = DB::transaction(function () use ($name, $adminName, $email, $phone, $password) {
            $bakery = Bakery::create([
                'name' => $name,
                ...$this->settingsToCopy(),
            ]);

            // Created inside the new shop, so the user and everything the
            // panel later sets up for them is stamped with it rather than
            // with whichever shop happened to be first in the table.
            return CurrentBakery::for($bakery->id, function () use ($bakery, $adminName, $email, $phone, $password) {
                $admin = User::create([
                    'name' => $adminName,
                    'email' => $email,
                    'phone' => $phone,
                    'password' => Hash::make($password),
                    'is_active' => true,
                    'bakery_id' => $bakery->id,
                ]);

                $admin->assignRole('admin');

                return $bakery;
            });
        });

        $this->info("نانوایی «{$bakery->name}» با شناسه {$bakery->id} ساخته شد.");
        $this->line("مدیر: {$adminName} — {$email}");
        $this->newLine();
        if ($this->option('like')) {
            $this->comment('فرمول، وزن‌ها و قیمت‌ها از نانوایی '
                .$this->option('like').' کپی شد. مدیر می‌تواند از پنل تغییرشان دهد.');
        } else {
            $this->comment('تنظیمات نانوایی (وزن کیسه، فرمول خمیر، قیمت نان) را مدیر'
                .' از پنل، بخش «اطلاعات نانوایی» وارد می‌کند.');
        }

        return self::SUCCESS;
    }

    /**
     * The recipe from another shop, if one was named.
     *
     * Identity is never copied — a new shop's name, address, phone, logo
     * and description are its own, and inheriting them would produce two
     * shops that look like the same shop.
     */
    private function settingsToCopy(): array
    {
        $id = $this->option('like');

        if (! $id) {
            return [];
        }

        $source = Bakery::find($id);

        if (! $source) {
            $this->warn("نانوایی با شناسه {$id} پیدا نشد؛ تنظیمات کپی نشد.");

            return [];
        }

        return collect($source->only($source->getFillable()))
            ->except(['name', 'address', 'phone', 'logo', 'description'])
            ->all();
    }
}
