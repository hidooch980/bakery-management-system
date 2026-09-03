<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Separates the day the sacks changed hands from the day somebody
     * typed them in.
     *
     * `occurred_on` has always meant the handover, and the chase counts a
     * fortnight from it. But an amanat nobody recorded at the time is
     * entered later with a date the owner does not know, and today's date
     * is what goes in the box — so the oldest debt in the shop arrives on
     * file looking like the newest.
     *
     * That is not hypothetical. The twenty sacks at نانوایی ناهوت went a
     * month with no record at all (نانوایی ناهوت was filed as «مدرسه» and
     * never appeared in the partner list), were entered on 1405/06/11 with
     * that day as the date, and the row's own note says so. The warning
     * that exists precisely to chase them would not have said a word until
     * 1405/06/25.
     *
     * A flag rather than a second date, because the honest answer here is
     * not a different day — it is that nobody knows the day. A row marked
     * this way is chased at once instead of waiting out a fortnight
     * measured from a date that means nothing.
     */
    public function up(): void
    {
        Schema::table('consignment_flours', function (Blueprint $table) {
            $table->boolean('date_is_approximate')->default(false)->after('occurred_on');
        });

        // The one row on file that says of itself that its date is the day
        // it was recorded. Matched on that sentence rather than on an id,
        // so this migration describes what it is fixing and does nothing
        // on a database where the sentence is absent.
        DB::table('consignment_flours')
            ->where('direction', 'lent')
            ->whereNull('settled_on')
            ->where('note', 'like', '%تاریخ تحویل نامعلوم%')
            ->update(['date_is_approximate' => true]);
    }

    public function down(): void
    {
        Schema::table('consignment_flours', function (Blueprint $table) {
            $table->dropColumn('date_is_approximate');
        });
    }
};
