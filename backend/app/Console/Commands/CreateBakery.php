<?php

namespace App\Console\Commands;

use App\Actions\OpenBakery;
use App\Models\Bakery;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Opens another shop on the same installation, from the server.
 *
 * The panel can do this too now, from the head shop — see
 * [App\Filament\Pages\OpenBakery]. Both go through the same action, so a
 * shop opened here and a shop opened there are the same shop; the failure
 * that would matter is the two drifting apart by one field and quietly
 * keeping different books.
 *
 * This one stays because it works before there is anyone to sign in as,
 * which is how the first shop of an install gets made.
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

        try {
            $bakery = app(OpenBakery::class)->run(
                name: $name,
                adminName: (string) $adminName,
                email: (string) $email,
                phone: filled($phone) ? $phone : null,
                password: (string) $password,
                copyFrom: $this->shopToCopy(),
            );
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

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
     * The shop whose recipe was asked for, if one was.
     *
     * An id that matches nothing warns and carries on with the defaults,
     * rather than refusing: the shop is still wanted, and its settings can
     * be typed in afterwards. Silence is the thing to avoid — a shop opened
     * with no recipe and no word said about it reports a flour loss that
     * never happened.
     */
    private function shopToCopy(): ?Bakery
    {
        $id = $this->option('like');

        if (! $id) {
            return null;
        }

        $source = Bakery::find($id);

        if (! $source) {
            $this->warn("نانوایی با شناسه {$id} پیدا نشد؛ تنظیمات کپی نشد.");
        }

        return $source;
    }
}
