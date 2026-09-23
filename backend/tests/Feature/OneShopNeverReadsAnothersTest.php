<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Bakery;
use App\Models\BakeryApplication;
use App\Models\BakeryShare;
use App\Models\BankAccount;
use App\Models\ConsignmentFlour;
use App\Models\Customer;
use App\Models\CustomerInteraction;
use App\Models\DieselDelivery;
use App\Models\Expense;
use App\Models\FlourAllocation;
use App\Models\Holiday;
use App\Models\Income;
use App\Models\Purchase;
use App\Models\SalaryPayment;
use App\Models\SalaryPaymentRequest;
use App\Models\SellerSettlementRecord;
use App\Models\SettlementRequest;
use App\Models\StaffAdjustment;
use App\Models\StaffAdvance;
use App\Models\StaffAdvanceRequest;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Support\CurrentBakery;
use App\Support\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * Every readable route, with two shops on the books.
 *
 * The wall between shops is the thing this system must get right before
 * it is ever sold to a second bakery, and it is not the sort of thing
 * that can be reasoned about one endpoint at a time: 46 of the 51 models
 * carry the scope, which sounds like enough until the five that do not
 * turn out to include `User`.
 *
 * So it is swept rather than argued, in two halves.
 *
 * The first: every shop-B row carries the same distinctive mark, and
 * every parameterless GET is called as shop A's owner. The mark must not
 * come back.
 *
 * The second: every route that takes an id is called as shop A's owner
 * with shop B's id in it. Reaching another shop's row by naming its
 * number is the other half of the same wall, and for a while this file
 * said out loud that it was «a different question» and left it out — 63
 * routes, none of them ever swept, several binding `User`, which is one
 * of the five models with no scope on it at all.
 *
 * It found two the first time it ran:
 *
 *     ✗ GET api/v1/attendance/roster
 *     ✗ GET api/v1/sales/staff
 *
 * Both listed every active person in the database, so one shop's owner
 * read another shop's staff by name.
 */
class OneShopNeverReadsAnothersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Distinctive enough that it cannot appear by chance, and plain ASCII
     * so it survives whatever encoding a response is built with.
     */
    private const MARK = 'ZZOTHERSHOPZZ';

    private User $ourOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $ours = Bakery::create(['name' => 'نانوایی ما', 'currency' => 'toman']);
        $theirs = Bakery::create(['name' => 'نانوایی دیگر '.self::MARK, 'currency' => 'toman']);
        Money::forgetCache();

        $this->fillTheOtherShop($theirs);

        CurrentBakery::actAs($ours->id);

        $this->ourOwner = User::factory()->create([
            'is_active' => true,
            'bakery_id' => $ours->id,
            'name' => 'مالکِ ما',
        ]);
        $this->ourOwner->assignRole('admin');

        // Nothing ambient left set: a request resolves its own shop from
        // the signed-in user, and leaving one forced here would test the
        // test rather than the code.
        CurrentBakery::forget();
    }

    /**
     * A shop with enough on its books that endpoints which only list
     * people who owe something have somebody to list.
     *
     * Learnt by running: with no advance on file, the payroll endpoint
     * reported no leak from a query that had no shop filter at all — its
     * rows were dropped by a `> 0` filter rather than by any wall. A
     * sweep is only as honest as the shop it sweeps against.
     */
    private function fillTheOtherShop(Bakery $theirs): void
    {
        CurrentBakery::actAs($theirs->id);

        $owner = User::factory()->create([
            'is_active' => true,
            'bakery_id' => $theirs->id,
            'name' => 'مالکِ دیگر '.self::MARK,
        ]);
        $owner->assignRole('admin');

        Customer::create(['name' => 'مشتریِ دیگر '.self::MARK, 'type' => 'partner']);
        Supplier::create(['name' => 'آسیابِ دیگر '.self::MARK]);

        Expense::create([
            'category' => 'fuel',
            'title' => 'هزینهٔ دیگر '.self::MARK,
            'amount' => 1_000_000,
            'spent_on' => now(),
            'user_id' => $owner->id,
        ]);

        StaffAdvance::create([
            'user_id' => $owner->id,
            'recorded_by' => $owner->id,
            'amount' => 500_000,
            'paid_on' => now(),
        ]);

        Attendance::create([
            'user_id' => $owner->id,
            'date' => now()->toDateString(),
            'checked_in_at' => now(),
        ]);
    }

    /** @return list<string> every parameterless GET under the api */
    private function readableRoutes(): array
    {
        $uris = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            // Routes taking an id are left out: reaching another shop's
            // row by guessing its number is a different question, and
            // answering it here would mean inventing ids rather than
            // sweeping what a signed-in owner can simply open.
            if (! str_starts_with($uri, 'api/v1') || str_contains($uri, '{')) {
                continue;
            }

            if (in_array('GET', $route->methods(), true)) {
                $uris[] = $uri;
            }
        }

        sort($uris);

        return $uris;
    }

    public function test_no_readable_route_returns_another_shops_rows(): void
    {
        $leaks = [];

        foreach ($this->readableRoutes() as $uri) {
            $response = $this->actingAs($this->ourOwner, 'sanctum')->getJson('/'.$uri);

            if (str_contains($response->getContent(), self::MARK)) {
                $leaks[] = 'GET '.$uri;
            }
        }

        $this->assertSame(
            [],
            $leaks,
            'این مسیرها دادهٔ نانوایی دیگر را برگرداندند: '.implode('، ', $leaks)
        );
    }

    /**
     * One row of every model a route can bind, on the other shop's books.
     *
     * Built inside [CurrentBakery::actAs] so the scope stamps each one
     * with shop B, exactly as a real request there would.
     *
     * @return array<class-string<Model>, int> model class => its id
     */
    private function theOtherShopsRows(Bakery $theirs, User $owner): array
    {
        return CurrentBakery::for($theirs->id, function () use ($owner) {
            $customer = Customer::create([
                'name' => 'مشتریِ ردیفی '.self::MARK,
                'type' => 'partner',
            ]);
            $supplier = Supplier::create(['name' => 'آسیابِ ردیفی '.self::MARK]);
            $account = BankAccount::create([
                'title' => 'حسابِ دیگر '.self::MARK,
                'is_active' => true,
            ]);
            $purchase = Purchase::create([
                'supplier_id' => $supplier->id,
                'user_id' => $owner->id,
                'purchased_on' => now(),
            ]);
            $payslip = SalaryPayment::create([
                'user_id' => $owner->id,
                'period_start' => now()->startOfMonth(),
                'period_label' => 'ماهِ دیگر',
                'base_amount' => 1_000_000,
            ]);
            $advance = StaffAdvance::create([
                'user_id' => $owner->id,
                'recorded_by' => $owner->id,
                'amount' => 100_000,
                'paid_on' => now(),
            ]);
            $allocation = FlourAllocation::create([
                'month_start' => FlourAllocation::monthStartFor(now()),
                'month_label' => 'سهمیهٔ دیگر',
                'total_bags' => 30,
            ]);

            return [
                BakeryApplication::class => BakeryApplication::create([
                    'bakery_name' => 'درخواستِ دیگر '.self::MARK,
                    'owner_name' => 'کسی',
                    'phone' => '09120009999',
                ])->id,
                BakeryShare::class => BakeryShare::create([
                    'name' => 'شریکِ دیگر '.self::MARK,
                    'dang' => 3,
                    'is_active' => true,
                ])->id,
                BankAccount::class => $account->id,
                ConsignmentFlour::class => ConsignmentFlour::create([
                    'user_id' => $owner->id,
                    'partner_name' => 'همکارِ دیگر '.self::MARK,
                    // «برده» نه «داده»: دادنِ آرد از انبار کم می‌کند و
                    // انبارِ نانوایی تازه خالی است.
                    'direction' => 'borrowed',
                    'bags' => 2,
                    'amount_kg' => 80,
                    'occurred_on' => now(),
                ])->id,
                Customer::class => $customer->id,
                CustomerInteraction::class => CustomerInteraction::create([
                    'customer_id' => $customer->id,
                    'user_id' => $owner->id,
                    'type' => 'call',
                    'summary' => 'گفت‌وگوی دیگر '.self::MARK,
                ])->id,
                DieselDelivery::class => DieselDelivery::create([
                    'user_id' => $owner->id,
                    'received_on' => now(),
                    'litres' => 100,
                    'amount' => 500_000,
                ])->id,
                Expense::class => Expense::create([
                    'category' => 'fuel',
                    'title' => 'هزینهٔ ردیفی '.self::MARK,
                    'amount' => 200_000,
                    'spent_on' => now(),
                    'user_id' => $owner->id,
                ])->id,
                FlourAllocation::class => $allocation->id,
                Holiday::class => Holiday::create([
                    'date' => now()->addDay(),
                    'title' => 'تعطیلیِ دیگر '.self::MARK,
                    'type' => 'shop',
                ])->id,
                Income::class => Income::create([
                    'user_id' => $owner->id,
                    'category' => 'other',
                    'title' => 'درآمدِ دیگر '.self::MARK,
                    'amount' => 300_000,
                    'received_on' => now(),
                ])->id,
                Purchase::class => $purchase->id,
                SalaryPayment::class => $payslip->id,
                SalaryPaymentRequest::class => SalaryPaymentRequest::create([
                    'user_id' => $owner->id,
                    'period_start' => now()->startOfMonth(),
                    'period_label' => 'ماهِ دیگر',
                    'status' => 'pending',
                ])->id,
                SellerSettlementRecord::class => SellerSettlementRecord::create([
                    'user_id' => $owner->id,
                    'settled_by' => $owner->id,
                    'kind' => 'settlement',
                    'amount' => 400_000,
                ])->id,
                SettlementRequest::class => SettlementRequest::create([
                    'user_id' => $owner->id,
                    'amount' => 400_000,
                ])->id,
                StaffAdjustment::class => StaffAdjustment::create([
                    'user_id' => $owner->id,
                    'recorded_by' => $owner->id,
                    'kind' => 'deduction',
                    'basis' => 'amount',
                    'amount' => 50_000,
                    'occurred_on' => now(),
                    'reason' => 'کسرِ دیگر '.self::MARK,
                ])->id,
                StaffAdvance::class => $advance->id,
                StaffAdvanceRequest::class => StaffAdvanceRequest::create([
                    'user_id' => $owner->id,
                    'amount' => 100_000,
                    'status' => 'pending',
                ])->id,
                Supplier::class => $supplier->id,
                SupplierPayment::class => SupplierPayment::create([
                    'supplier_id' => $supplier->id,
                    'user_id' => $owner->id,
                    'amount' => 100_000,
                    'paid_on' => now(),
                ])->id,
                User::class => $owner->id,
            ];
        });
    }

    /**
     * Every route that takes exactly one bound model, with what it binds.
     *
     * Read off the controller's own signature rather than guessed from
     * the url: `{seller}`, `{person}` and `{user}` are all `User`, and a
     * sweep that matched on the word in the braces would have missed two
     * of the three.
     *
     * @return list<array{method: string, uri: string, model: class-string<Model>, name: string}>
     */
    private function routesTakingAnId(): array
    {
        $found = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/v1') || ! str_contains($uri, '{')) {
                continue;
            }

            $action = $route->getAction('controller');

            if (! is_string($action) || ! str_contains($action, '@')) {
                continue;
            }

            [$class, $method] = explode('@', $action);

            if (! class_exists($class) || ! method_exists($class, $method)) {
                continue;
            }

            $bound = [];

            foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
                $type = $parameter->getType();

                if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }

                if (is_subclass_of($type->getName(), Model::class)) {
                    $bound[] = [$parameter->getName(), $type->getName()];
                }
            }

            // Exactly one, so the id put in the url is unambiguous.
            if (count($bound) !== 1) {
                continue;
            }

            foreach ($route->methods() as $verb) {
                if ($verb === 'HEAD') {
                    continue;
                }

                $found[] = [
                    'method' => $verb,
                    'uri' => $uri,
                    'model' => $bound[0][1],
                    'name' => $bound[0][0],
                ];
            }
        }

        return $found;
    }

    public function test_no_route_lets_one_shop_reach_anothers_row_by_its_id(): void
    {
        $theirs = Bakery::query()->where('name', 'like', '%'.self::MARK.'%')->first();
        $theirOwner = User::query()->where('bakery_id', $theirs->id)->first();

        $rows = $this->theOtherShopsRows($theirs, $theirOwner);

        CurrentBakery::forget();

        $reached = [];
        $leaks = [];

        foreach ($this->routesTakingAnId() as $route) {
            if (! isset($rows[$route['model']])) {
                continue;
            }

            $uri = preg_replace(
                '/\{'.preg_quote($route['name'], '/').'\??\}/',
                (string) $rows[$route['model']],
                $route['uri'],
            );

            // Still has a brace: a second parameter this sweep cannot
            // fill. Left alone rather than called with a broken url.
            if (str_contains($uri, '{')) {
                continue;
            }

            $response = $this->actingAs($this->ourOwner, 'sanctum')
                ->json($route['method'], '/'.$uri);

            $reached[] = $route['method'].' '.$route['uri'];

            // 404 is the right answer and so is 403. What must never
            // happen is the request going through: a 2xx here means one
            // shop read — or worse, changed — another shop's row.
            if ($response->getStatusCode() < 300) {
                $leaks[] = $route['method'].' '.$route['uri'];
            }
        }

        $this->assertSame(
            [],
            $leaks,
            'این مسیرها با شناسهٔ نانوایی دیگر پاسخ دادند: '.implode('، ', $leaks)
        );

        // جارویی که بی‌صدا سه مسیر را گرفته باشد هیچ چیز ثابت نمی‌کند.
        // این عدد کف است نه هدف: با اضافه‌شدن مسیر بالا می‌رود و قرار
        // است با آن‌ها بالا برده شود. امروز ۶۶ تا را می‌گیرد.
        $this->assertGreaterThanOrEqual(
            60,
            count($reached),
            'جارو به اندازهٔ کافی مسیر را نگرفت — فقط '.count($reached).' تا.'
        );
    }

    /**
     * همان نشانی‌ها، این بار از سوی صاحبِ خودِ آن نانوایی.
     *
     * بدون این، جاروی بالا می‌تواند با نشانی‌های خراب هم سبز بماند: هر
     * درخواست ۴۰۴ می‌گیرد، هیچ نشتی گزارش نمی‌شود، و دیوار اصلاً
     * آزموده نشده. اینجا ثابت می‌شود همان ردیف‌ها برای کسی که حقش را
     * دارد واقعاً باز می‌شوند.
     *
     * فقط خواندن. نوشتن و پاک‌کردن را با شناسهٔ واقعی صدا نمی‌زنم —
     * آن دیگر آزمونِ دیوار نیست، آزمونِ خودِ آن مسیرهاست که جای دیگری
     * دارد.
     */
    public function test_the_same_rows_do_open_for_the_shop_they_belong_to(): void
    {
        $theirs = Bakery::query()->where('name', 'like', '%'.self::MARK.'%')->first();
        $theirOwner = User::query()->where('bakery_id', $theirs->id)->first();

        $rows = $this->theOtherShopsRows($theirs, $theirOwner);

        CurrentBakery::forget();

        $opened = 0;

        foreach ($this->routesTakingAnId() as $route) {
            if ($route['method'] !== 'GET' || ! isset($rows[$route['model']])) {
                continue;
            }

            $uri = preg_replace(
                '/\{'.preg_quote($route['name'], '/').'\??\}/',
                (string) $rows[$route['model']],
                $route['uri'],
            );

            if (str_contains($uri, '{')) {
                continue;
            }

            if ($this->actingAs($theirOwner, 'sanctum')->getJson('/'.$uri)->getStatusCode() < 300) {
                $opened++;
            }
        }

        $this->assertGreaterThanOrEqual(
            5,
            $opened,
            'هیچ‌کدام از این نشانی‌ها برای صاحبِ خودشان هم باز نشد — '
            .'یعنی جاروی بالا دارد نشانیِ خراب را جارو می‌کند نه دیوار را.'
        );
    }

    public function test_the_sweep_actually_reaches_the_routes(): void
    {
        // A sweep that 403s its way through every route reports no leak
        // and proves nothing. This is what makes the assertion above mean
        // something.
        $reached = 0;
        $routes = $this->readableRoutes();

        foreach ($routes as $uri) {
            if ($this->actingAs($this->ourOwner, 'sanctum')->getJson('/'.$uri)->status() === 200) {
                $reached++;
            }
        }

        $this->assertGreaterThan(80, $reached, "only {$reached} routes answered");
        $this->assertGreaterThan(90, count($routes));
    }

    public function test_the_mark_is_really_there_to_be_found(): void
    {
        // The other shop's owner sees their own rows. Without this, a
        // sweep that found nothing might be sweeping an empty shop.
        $theirOwner = User::withoutGlobalScopes()
            ->where('name', 'like', '%'.self::MARK.'%')
            ->firstOrFail();

        $response = $this->actingAs($theirOwner, 'sanctum')
            ->getJson('/api/v1/sales/staff');

        $this->assertStringContainsString(self::MARK, $response->getContent());
    }
}
