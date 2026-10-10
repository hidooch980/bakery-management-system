<?php

namespace App\Filament\Resources\PartnerResource\Pages;

use App\Filament\Resources\PartnerResource;
use App\Models\ConsignmentFlour;
use App\Models\Customer;
use App\Support\Jalali;
use App\Support\PartnerStatement;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * پروندهٔ یک همکار: سرخط مانده (طلب ما / بدهی ما / تسویه)، فیلتر تاریخ
 * شمسی، و گردش ریز به کیسه با ماندهٔ بعد از هر ردیف. چاپ و خروجی CSV.
 */
class PartnerStatementPage extends Page
{
    use InteractsWithRecord;

    protected static string $resource = PartnerResource::class;

    protected static string $view = 'filament.pages.partner-statement';

    /** تاریخ شمسی به شکل متن، مثل 1405/05/01. */
    public ?string $from = null;

    public ?string $to = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless($this->record instanceof Customer && $this->record->type === Customer::PARTNER_TYPE, 404);
    }

    public function getTitle(): string|Htmlable
    {
        return 'پروندهٔ '.$this->record->name;
    }

    public function getBreadcrumb(): string
    {
        return 'پرونده';
    }

    public function statement(): PartnerStatement
    {
        return PartnerStatement::for(
            $this->record,
            Jalali::parseFlexible($this->from),
            Jalali::parseFlexible($this->to),
        );
    }

    public function clearFilter(): void
    {
        $this->from = null;
        $this->to = null;
    }

    public function exportCsv(): StreamedResponse
    {
        $csv = $this->statement()->toCsv();
        $name = 'partner-'.$this->record->id.'-statement.csv';

        return response()->streamDownload(fn () => print ($csv), $name, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('print')
                ->label('چاپ')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->extraAttributes(['onclick' => 'window.print(); return false;']),

            Actions\Action::make('csv')
                ->label('خروجی CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => $this->exportCsv()),

        ];
    }

    /** ردیف‌های تسویه‌نشدهٔ همین همکار. */
    public function openRecords()
    {
        return ConsignmentFlour::query()
            ->where('customer_id', $this->record->getKey())
            ->whereNull('settled_on')
            ->with('returns')
            ->orderBy('occurred_on')
            ->get();
    }

    /** مشترک با فهرست آرد امانی. */
    public static function storeReturn(array $data, ?ConsignmentFlour $record = null): void
    {
        $record ??= ConsignmentFlour::findOrFail($data['consignment_flour_id']);

        try {
            $record->recordReturn(
                (float) $data['bags'],
                $data['returned_on'] ?? now(),
                $data['note'] ?? null,
                auth()->id(),
            );
        } catch (\InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title('برگشت ثبت شد.')->send();
    }
}
