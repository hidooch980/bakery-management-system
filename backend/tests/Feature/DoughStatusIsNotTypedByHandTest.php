<?php

namespace Tests\Feature;

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
        // Read off the resource's own source rather than built as a form:
        // building one needs a Livewire host, and the guarantee wanted here
        // is simply that nobody quietly deletes the `disabled()` line.
        $source = file_get_contents(app_path('Filament/Resources/DoughEntryResource.php'));

        $statusField = substr($source, strpos($source, "Select::make('status')"));
        $statusField = substr($statusField, 0, strpos($statusField, '),'));

        $this->assertStringContainsString('->disabled()', $statusField,
            'وضعیت خمیر دوباره دستی قابل انتخاب شده است.');
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
