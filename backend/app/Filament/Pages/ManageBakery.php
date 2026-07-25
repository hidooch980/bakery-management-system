<?php

namespace App\Filament\Pages;

use App\Models\Bakery;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class ManageBakery extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationGroup = 'تنظیمات';

    protected static ?string $navigationLabel = 'اطلاعات نانوایی';

    protected static ?string $title = 'اطلاعات نانوایی';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.manage-bakery';

    public ?array $data = [];

    public function mount(): void
    {
        $bakery = Bakery::firstOrCreate(['id' => 1], ['name' => 'نانوایی من']);
        $this->form->fill($bakery->toArray());
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('مشخصات نانوایی')
                    ->description('این اطلاعات در اپلیکیشن موبایل کارکنان نمایش داده می‌شود.')
                    ->icon('heroicon-o-building-storefront')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('نام نانوایی')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('phone')
                            ->label('تلفن')
                            ->tel()
                            ->maxLength(20),

                        Forms\Components\TextInput::make('address')
                            ->label('آدرس')
                            ->maxLength(500)
                            ->columnSpanFull(),

                        Forms\Components\FileUpload::make('logo')
                            ->label('لوگو')
                            ->image()
                            ->imageEditor()
                            ->directory('bakery')
                            ->maxSize(2048)
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('description')
                            ->label('توضیحات')
                            ->rows(3)
                            ->maxLength(1000)
                            ->columnSpanFull(),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        Bakery::firstOrNew(['id' => 1])->fill($data)->save();

        Notification::make()
            ->title('اطلاعات نانوایی ذخیره شد.')
            ->success()
            ->send();
    }

    protected function getFormActions(): array
    {
        return [
            \Filament\Actions\Action::make('save')
                ->label('ذخیره تغییرات')
                ->submit('save')
                ->icon('heroicon-o-check'),
        ];
    }
}
