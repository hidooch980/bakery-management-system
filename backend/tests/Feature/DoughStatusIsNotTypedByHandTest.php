<?php

namespace Tests\Feature;

use App\Filament\Resources\DoughEntryResource;
use Filament\Forms\Components\Select;
use Tests\TestCase;

/**
 * A dough's status belongs to the chane that closes it.
 *
 * `processed` is written in exactly one place — ProductionRecorder, when a
 * chane is recorded against the dough. The panel's create form offered it
 * as a choice anyway, and choosing it was a one-way door: the dough leaves
 * the `pending()` scope, so no chane can ever be recorded against it,
 * while the flour it consumed has already left the store. The batch is
 * then unaccounted for in both directions at once.
 */
class DoughStatusIsNotTypedByHandTest extends TestCase
{
    public function test_the_status_field_cannot_be_edited_in_the_panel(): void
    {
        $status = collect(DoughEntryResource::form(app(\Filament\Forms\Form::class))->getComponents())
            ->flatMap(fn ($component) => $component->getChildComponents())
            ->first(fn ($component) => $component instanceof Select
                && $component->getName() === 'status');

        $this->assertNotNull($status, 'فیلد وضعیت از فرم ناپدید شده است.');
        $this->assertTrue($status->isDisabled(), 'وضعیت خمیر دستی قابل انتخاب است.');
    }

    public function test_only_the_production_recorder_writes_processed(): void
    {
        // A grep as a test, deliberately. The guarantee this rests on is
        // «one writer», and the cheapest way to keep that true is to fail
        // the build the day a second one appears.
        $writers = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $body = file_get_contents($file->getPathname());
            if (preg_match("/'status'\s*=>\s*'processed'/", $body)) {
                $writers[] = basename($file->getPathname());
            }
        }

        $this->assertSame(['ProductionRecorder.php'], $writers);
    }
}
