<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Who the shop buys from, and what arrived on the lorry.
 *
 * A delivery of flour used to become three unconnected rows: an expense
 * with the category «خرید آرد» for the money, an inventory movement for
 * the sacks, and a dated flour price for the rate. Nothing joined them,
 * nothing said who delivered it, and any one of the three could be
 * forgotten without the other two complaining. The shop has bought flour
 * on credit since it opened and the only record of what it owed a mill
 * was the mill's own book.
 *
 * One invoice, one record. The lines say what came in and the warehouse
 * follows them; the money says what was handed over and the bank follows
 * that; the difference is what is still owed, and it has a name on it.
 *
 * Amounts are stored the way every other amount in this project is —
 * Toman in the column, Rial on the screen. See Money.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            // Free text on purpose. «کارخانه آرد زاهدان», «بنکدار»,
            // «حمل و نقل» — the shop's own words for what somebody is,
            // not a list this project invented and made them choose from.
            $table->string('kind')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('note')->nullable();
            $table->foreignId('bakery_id')->nullable()->index();
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // The mill's own number, so a disputed delivery can be found in
            // their book as well as this one.
            $table->string('invoice_no')->nullable();
            $table->date('purchased_on');
            // Rebuilt from the lines on every save. Stored rather than
            // summed on read because every report that asks what the shop
            // spent would otherwise join three tables to find out.
            $table->decimal('amount', 14, 2)->default(0);
            // What was handed over at the door. The rest is a debt, and
            // is paid down through supplier_payments.
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('bakery_id')->nullable()->index();
            $table->timestamps();

            $table->index('purchased_on');
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            // Null for a line that is money without goods: freight,
            // unloading, the mill's own commission. It still counts
            // towards the invoice; it just never reaches the warehouse.
            $table->foreignId('inventory_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->nullable();
            // Sacks are what is counted off the lorry; kilograms are what
            // the warehouse holds. Both are stored, and the second is
            // derived from the first the way ConsignmentFlour does it, so
            // the two cannot be typed into disagreement.
            $table->decimal('bags', 10, 2)->nullable();
            $table->decimal('quantity_kg', 12, 3)->default(0);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('amount', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            // A payment against one invoice, or against the account as a
            // whole — which is how this shop actually pays a mill: a round
            // number on account, not invoice by invoice.
            $table->foreignId('purchase_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->date('paid_on');
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('bakery_id')->nullable()->index();
            $table->timestamps();

            $table->index('paid_on');
        });

        // The rate on a flour line is the shop's buying price on the day
        // it was delivered, and the cost of goods reads that price to
        // charge each bake. Without this link the price would have to be
        // typed a second time on its own screen — and the one that was
        // forgotten is the one the profit statement would use.
        //
        // Nullable, because a price the owner types by hand has no
        // invoice behind it and must stay editable.
        Schema::table('flour_prices', function (Blueprint $table) {
            $table->foreignId('purchase_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();
        });

        $this->grantPermissions();
    }

    /**
     * Two permissions rather than one.
     *
     * Recording what arrived at the door is not the same act as agreeing
     * what the shop owes for it. The seller is the one on the floor when
     * the lorry comes — the same reason they tick the bakers in and book
     * a diesel docket — so they may write the delivery down. The account
     * with the mill, the payments against it, and correcting an invoice
     * already filed stay with whoever holds the money.
     */
    private function grantPermissions(): void
    {
        $grants = [
            'manage-purchases' => ['admin'],
            'record-purchase' => ['admin', 'seller'],
        ];

        foreach ($grants as $name => $roles) {
            $permission = Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);

            foreach ($roles as $role) {
                Role::where('name', $role)
                    ->where('guard_name', 'web')
                    ->first()
                    ?->givePermissionTo($permission);
            }
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', ['manage-purchases', 'record-purchase'])
            ->where('guard_name', 'web')
            ->delete();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Schema::table('flour_prices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_id');
        });

        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('suppliers');
    }
};
