<?php

use App\Http\Controllers\Api\AppReleaseController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BackupController;
use App\Http\Controllers\Api\BakeryApplicationController;
use App\Http\Controllers\Api\BakeryController;
use App\Http\Controllers\Api\BakeryShareController;
use App\Http\Controllers\Api\BalanceSheetController;
use App\Http\Controllers\Api\BankAccountController;
use App\Http\Controllers\Api\BankSmsController;
use App\Http\Controllers\Api\CashCountController;
use App\Http\Controllers\Api\ChaneBoardController;
use App\Http\Controllers\Api\ChaneEntryController;
use App\Http\Controllers\Api\ConsignmentFlourController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\CustomerDebtController;
use App\Http\Controllers\Api\CustomerInteractionController;
use App\Http\Controllers\Api\DoughEntryController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\FlourAllocationController;
use App\Http\Controllers\Api\FlourDayController;
use App\Http\Controllers\Api\FlourSaleController;
use App\Http\Controllers\Api\FlourStockController;
use App\Http\Controllers\Api\HolidayController;
use App\Http\Controllers\Api\IncomeController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\LoanController;
use App\Http\Controllers\Api\PowerBiExportController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\QuotaController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SalaryController;
use App\Http\Controllers\Api\SalaryPaymentRequestController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\SellerAccountController;
use App\Http\Controllers\Api\SellerCollectionController;
use App\Http\Controllers\Api\SellerPerformanceController;
use App\Http\Controllers\Api\SettlementRequestController;
use App\Http\Controllers\Api\StaffAccountController;
use App\Http\Controllers\Api\StaffAdjustmentController;
use App\Http\Controllers\Api\StaffAdvanceController;
use App\Http\Controllers\Api\StaffAdvanceRequestController;
use App\Http\Controllers\Api\StaffPenaltyRequestController;
use App\Http\Controllers\Api\StaffReportController;
use App\Http\Controllers\Api\StockCountController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\SupplierPaymentController;
use App\Http\Controllers\Api\TodayController;
use App\Http\Controllers\Api\UserManagementController;
use App\Http\Controllers\Api\WorkStartController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Bakery Management API (v1)
|--------------------------------------------------------------------------
| Every route below is permission-gated with spatie/laravel-permission.
| There is no public registration route — only an admin may create accounts.
*/

Route::prefix('v1')->group(function () {
    // Unauthenticated on purpose and deliberately empty of detail: the app
    // asks it only "are you the bakery, and are you up?" while deciding
    // which published address to talk to during a server move.
    Route::get('/health', fn () => response()->json([
        'success' => true,
        'service' => 'bakery',
    ]));

    // The newest APK published on this server, for the in-app updater.
    // Public because it is asked before sign-in; it names a version and a
    // file anyone can download. Every phone asks once a launch — thirty a
    // minute per address is far above that and far below a scraper.
    Route::get('/app/latest', [AppReleaseController::class, 'latest'])
        ->middleware('throttle:30,1');

    // Five a minute. Ten was set when nobody had counted how many people
    // actually log in here: five staff, once a day each, on phones that
    // keep them signed in. Anything faster than five is not this shop.
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

    // A bakery asking to use this. Unauthenticated on purpose and dull
    // on purpose: it writes a row saying somebody asked, and creates no
    // shop, no login and no entitlement. The owner opens the shop
    // afterwards, signed in, from the other half of this controller.
    //
    // Throttled harder than login. There is no account behind this to
    // lock, so the rate is the only wall there is.
    Route::post('/bakery-applications', [BakeryApplicationController::class, 'store'])
        ->middleware('throttle:3,10');

    // Forgotten passwords, by text. Both answer the same way whether the
    // number is registered or not, so neither can be used to find out who
    // works here. The throttle is a second wall: the real limit is counted
    // per phone number inside the controller, because that is what costs
    // money and rings at night.
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])
        ->middleware('throttle:6,1');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])
        ->middleware('throttle:10,1');

    // `idempotent` sits inside the auth group, not on the api group: it
    // needs to know who is asking, and group middleware runs before
    // sanctum has resolved the user. It is a no-op unless the client
    // sends an Idempotency-Key, so older app versions are unaffected.
    // `active` runs after sanctum has resolved the user and before
    // anything reads or writes: an account switched off must stop working
    // on the next request, not at the next sign-in it never makes.
    // `picks-bakery` sits right after sanctum and before anything reads:
    // an owner with several shops names the one on screen, and it has to
    // be in force before the first global scope runs, not after.
    Route::middleware([
        'auth:sanctum', 'active', 'app-version', 'picks-bakery', 'idempotent',
    ])->group(function () {
        // --- Available to every authenticated user ---
        Route::get('/me', [AuthC…102675 tokens truncated…or a
        // while and the seller had to add up off three numbers nobody
        // showed them.
        //
        // Summed by the server off those same periods, so it can never
        // disagree with the card above it.
        if (_whole != null) ...[
          _WholePeriodRule(label: _whole!['label'] as String?),
          _QuotaCard(period: _whole!),
        ],
      ],
    );
  }
}

/// «جمع هر سه دوره» — a rule and a word.
///
/// Without it a second card under the first reads as a second period, or
/// worse as a correction of the one above it.
class _WholePeriodRule extends StatelessWidget {
  const _WholePeriodRule({required this.label});

  final String? label;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Padding(
      padding: const EdgeInsets.only(top: 14, bottom: 10),
      child: Row(
        children: [
          Expanded(child: Divider(color: scheme.outlineVariant)),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 10),
            child: Text(
              label ?? 'جمع هر سه دوره',
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: scheme.onSurfaceVariant,
                    fontWeight: FontWeight.w700,
                  ),
            ),
          ),
          Expanded(child: Divider(color: scheme.outlineVariant)),
        ],
      ),
    );
  }
}

/// The three bread figures for one period, or for all three added up.
///
/// One widget for both so the total reads as the same kind of thing as
/// the period above it — the owner's screen makes the same choice, and
/// two different-looking cards would invite the question of why.
class _QuotaCard extends StatelessWidget {
  const _QuotaCard({required this.period});

  final Map<String, dynamic> period;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final allocated = (period['allocated_bread_count'] as num?)?.toInt() ?? 0;
    final sold = (period['card_bread_count'] as num?)?.toInt() ?? 0;
    final left = (period['bread_remainder'] as num?)?.toInt() ?? 0;
    final over = left < 0;

    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(18, 16, 18, 16),
        child: Column(
          children: [
            DailyFlourQuota(period: period),
            Row(
              children: [
                Expanded(
                  child: _QuotaFigure(
                    label: 'نان دوره',
                    value: allocated,
                    color: scheme.onSurface,
                  ),
                ),
                Expanded(
                  child: _QuotaFigure(
                    label: 'فروش کارتخوان',
                    value: sold,
                    color: AppColors.moneyIn,
                  ),
                ),
              ],
            ),
            const Divider(height: 26),
            Row(
              children: [
                Icon(
                  over
                      ? Icons.error_outline_rounded
                      : Icons.trending_flat_rounded,
                  size: IconSize.row,
                  color: over ? AppColors.moneyOut : scheme.onSurfaceVariant,
                ),
                const SizedBox(width: 8),
                Text(
                  over ? 'بیش از سهمیه' : 'باقی‌مانده',
                  style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                        color: scheme.onSurfaceVariant,
                      ),
                ),
                const Spacer(),
                Text(
                  // The absolute value with a word beside it, never a
                  // minus sign: «-۴۰۰ نان باقی‌مانده» is a sentence
                  // nobody can act on.
                  '${left.abs()} نان',
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w800,
                        color: over ? AppColors.moneyOut : AppColors.moneyIn,
                      ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _QuotaFigure extends StatelessWidget {
  const _QuotaFigure({
    required this.label,
    required this.value,
    required this.color,
  });

  final String label;
  final int value;
  final Color color;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Column(
      children: [
        Text(
          '$value',
          style: Theme.of(context).textTheme.titleLarge?.copyWith(
                fontWeight: FontWeight.w800,
                color: color,
              ),
        ),
        const SizedBox(height: 3),
        Text(
          label,
          textAlign: TextAlign.center,
          style: Theme.of(context).textTheme.bodySmall?.copyWith(
                color: scheme.onSurfaceVariant,
              ),
        ),
      ],
    );
  }
}

class _FlourSection extends StatefulWidget {
  const _FlourSection({
    required this.api,
    required this.onChanged,
    this.bakery,
  });

  final BakeryApi api;
  final Bakery? bakery;
  final VoidCallback onChanged;

  @override
  State<_FlourSection> createState() => _FlourSectionState();
}

class _FlourSectionState extends State<_FlourSection> {
  FlourSaleOptions? _stock;

  @override
  void initState() {
    super.initState();
    _loadStock();
  }

  Future<void> _loadStock() async {
    try {
      final stock = await widget.api.flourSaleOptions();
      if (mounted) setState(() => _stock = stock);
    } on ApiException {
      // The two buttons below still work; the heading simply says nothing
      // rather than showing a figure it could not confirm.
    }
  }

  Future<void> _open(BuildContext context, AdminRecordKind kind) async {
    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (_) => AdminRecordSheet(
        api: widget.api,
        kind: kind,
        bakery: widget.bakery,
      ),
    );

    if (saved == true) {
      widget.onChanged();
      await _loadStock();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _Heading(
          title: 'انبار',
          icon: Icons.grain_rounded,
          trailing: _stock == null ? null : InkWell(
            onTap: () => showInventoryEntries(context, api: widget.api,
              itemKey: 'flour', itemName: 'آرد', subtitle: 'ریز گردش اخیر'),
            child: Padding(padding: const EdgeInsets.all(6),
              child: _FlourOnHand(stock: _stock!)),
          ),
        ),
        Row(
          children: [
            Expanded(
              child: OutlinedButton.icon(
                onPressed: () => _open(context, AdminRecordKind.intake),
                icon: const Icon(Icons.local_shipping_rounded, size: IconSize.row),
                label: const Text('ورودی انبار'),
                style: OutlinedButton.styleFrom(
                  minimumSize: const Size.fromHeight(50),
                ),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: OutlinedButton.icon(
                onPressed: () => _open(context, AdminRecordKind.consignment),
                icon: const Icon(Icons.swap_horiz_rounded, size: IconSize.row),
                label: const Text('آرد همکار'),
                style: OutlinedButton.styleFrom(
                  minimumSize: const Size.fromHeight(50),
                ),
              ),
            ),
          ],
        ),
        const SizedBox(height: 10),
        // «ورودی انبار» above is stock arriving with no invoice behind it
        // — an opening balance, a count that came up short. This one is a
        // delivery: it carries the mill's name and what the shop owes for
        // it, and the docket is in somebody's pocket by the time it would
        // otherwise reach a desk.
        FilledButton.icon(
          onPressed: () async {
            if (await PurchaseSheet.open(context, widget.api)) {
              widget.onChanged();
              await _loadStock();
            }
          },
          icon: const Icon(Icons.receipt_long_rounded, size: IconSize.row),
          label: const Text('ثبت محموله با فاکتور'),
          style: FilledButton.styleFrom(
            minimumSize: const Size.fromHeight(50),
          ),
        ),
      ],
    );
  }
}

/// «۱۹۳ کیسه · ۷٬۷۲۰ کگ» — what is in the store, at a glance.
///
/// Coloured only when it is low. A figure that is always tinted is a
/// figure nobody reads a warning into; this one stays plain until there
/// really is something to say, and the threshold is the shop's own, set on
/// the warehouse item rather than guessed at here.
class _FlourOnHand extends StatelessWidget {
  const _FlourOnHand({required this.stock});

  /// Ten sacks: about a week of baking at this shop's rate, which is time
  /// enough to order more before the oven stops.
  static const _lowBags = 10;

  final FlourSaleOptions stock;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final low = stock.availableBags < _lowBags;

    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        if (low)
          Padding(
            padding: const EdgeInsetsDirectional.only(end: 4),
            child: Icon(
              Icons.error_outline_rounded,
              size: IconSize.inline,
              color: AppColors.attention,
            ),
          ),
        Text(
          '${stock.availableBags.toStringAsFixed(stock.availableBags % 1 == 0 ? 0 : 1)} کیسه',
          style: theme.textTheme.titleSmall?.copyWith(
            fontWeight: FontWeight.w700,
            color: low ? AppColors.attention : null,
            fontFeatures: const [FontFeature.tabularFigures()],
          ),
        ),
        Text(
          '  ·  ${stock.availableKg.toStringAsFixed(0)} کگ',
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.onSurface.withValues(alpha: 0.6),
            fontFeatures: const [FontFeature.tabularFigures()],
          ),
        ),
      ],
    );
  }
}

// ------------------------------------------------------------- attendance

/// Who is in today, for the whole floor rather than just the person looking.
class StaffAttendanceSection extends StatefulWidget {
  const StaffAttendanceSection({super.key, required this.api});

  final BakeryApi api;

  @override
  State<StaffAttendanceSection> createState() => _StaffAttendanceSectionState();
}

class _StaffAttendanceSectionState extends State<StaffAttendanceSection> {
  late Future<List<Map<String, dynamic>>> _records;

  /// Why the list is empty, when it is empty for a reason other than
  /// nobody having ticked in yet.
  String? _failure;

  @override
  void initState() {
    super.initState();
    _records = _load();
  }

  Future<List<Map<String, dynamic>>> _load() async {
    try {
      final records = await widget.api.adminAttendanceToday();
      if (mounted) setState(() => _failure = null);
      return records;
    } on ApiException catch (e) {
      // This used to swallow the error and return an empty list, so a
      // refusal, a timeout and a genuinely quiet morning all rendered as
      // «هنوز کسی تیک حضور نزده» — a sentence that is a fact in one case
      // and a lie in the other two, with nothing on screen to tell them
      // apart.
      if (mounted) setState(() => _failure = e.message);
      return const [];
    }
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return FutureBuilder<List<Map<String, dynamic>>>(
      future: _records,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting) {
          return const SizedBox.shrink();
        }

        final records = snapshot.data ?? const <Map<String, dynamic>>[];

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _Heading(
              title: 'حاضری امروز',
              icon: Icons.how_to_reg_rounded,
              trailing: Text(
                '${records.length} نفر',
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      color: scheme.onSurfaceVariant,
                      fontWeight: FontWeight.w700,
                    ),
              ),
            ),
            if (records.isEmpty)
              Card(
                child: Padding(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 18, vertical: 16),
                  child: Row(
                    children: [
                      if (_failure != null) ...[
                        Icon(
                          Icons.cloud_off_rounded,
                          size: 18,
                          color: scheme.error,
                        ),
                        const SizedBox(width: 8),
                      ],
                      Expanded(
                        child: Text(
                          _failure ?? 'هنوز کسی تیک حضور نزده.',
                          style:
                              Theme.of(context).textTheme.bodyMedium?.copyWith(
                                    color: _failure != null ? scheme.error : null,
                                  ),
                        ),
                      ),
                      if (_failure != null)
                        TextButton(
                          onPressed: () =>
                              setState(() { _records = _load(); }),
                          child: const Text('دوباره'),
                        ),
                    ],
                  ),
                ),
              )
            else
              Card(
                child: Column(
                  children: [
                    for (var i = 0; i < records.length; i++) ...[
                      if (i > 0) const Divider(height: 1),
                      _AttendanceRow(record: records[i]),
                    ],
                  ],
                ),
              ),
          ],
        );
      },
    );
  }
}

class _AttendanceRow extends StatelessWidget {
  const _AttendanceRow({required this.record});

  final Map<String, dynamic> record;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final user = keyedGroup(record['user']);
    final checkedInAt = DateTime.tryParse('${record['checked_in_at']}');

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 12),
      child: Row(
        children: [
          Icon(Icons.person_rounded, size: IconSize.button, color: scheme.onSurfaceVariant),
          const SizedBox(width: 12),
          Expanded(
            child: Text(
              personName(user, fallbackId: record['user_id']),
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                      fontWeight: FontWeight.w600,
                      color: Theme.of(context).colorScheme.onSurface,
                    ),
            ),
          ),
          Text(
            JalaliFormat.time(checkedInAt),
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  fontWeight: FontWeight.w700,
                  color: AppColors.moneyIn,
                ),
          ),
        ],
      ),
    );
  }
}
