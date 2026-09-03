<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Settings;

use App\Domain\Settings\SettingsRepository;
use App\Enums\SettingType;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Settings')]
class ManageSettings extends Component
{
    /**
     * Current form values, keyed by setting key.
     *
     * @var array<string, string|bool|null>
     */
    public array $values = [];

    public function mount(): void
    {
        $this->authorize('settings.view');

        foreach ($this->settings() as $setting) {
            $this->values[$setting->key] = $setting->type === SettingType::Boolean
                ? (bool) $setting->type->cast($setting->value)
                : $setting->value;
        }
    }

    public function save(SettingsRepository $settings): void
    {
        $this->authorize('settings.update');

        $this->validate($this->rules(), [], $this->validationAttributes());

        foreach ($this->settings() as $setting) {
            $value = $this->values[$setting->key] ?? null;

            // An empty text box means "not configured", which is a different
            // thing from the empty string and is stored as null.
            if ($setting->type !== SettingType::Boolean && $value === '') {
                $value = null;
            }

            $settings->set($setting->key, $value);
        }

        $this->dispatch('settings-saved');
    }

    /**
     * Validation derived from each setting's declared type, so a new setting
     * added to the seeder is validated correctly without touching this class.
     *
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        $rules = [];

        foreach ($this->settings() as $setting) {
            $key = "values.{$setting->key}";

            $rules[$key] = match ($setting->type) {
                SettingType::Integer => ['nullable', 'integer'],
                SettingType::Boolean => ['boolean'],
                SettingType::Money => ['nullable', 'integer', 'min:0'],
                SettingType::String => ['nullable', 'string', 'max:500'],
            };
        }

        // Values the application itself depends on being well formed.
        $rules['values.currency'] = ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'];
        $rules['values.site_name'] = ['required', 'string', 'max:100'];
        $rules['values.display_timezone'] = ['required', 'string', 'timezone'];
        $rules['values.support_email'] = ['nullable', 'email', 'max:255'];

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return $this->settings()
            ->mapWithKeys(fn (Setting $s): array => ["values.{$s->key}" => strtolower($s->label)])
            ->all();
    }

    /**
     * @return Collection<int, Setting>
     */
    public function settings(): Collection
    {
        return Setting::orderBy('group')->orderBy('id')->get();
    }

    /**
     * @return Collection<string, Collection<int, Setting>>
     */
    public function groupedSettings(): Collection
    {
        return $this->settings()->groupBy('group');
    }

    public function render(): View
    {
        return view('livewire.admin.settings.manage-settings', [
            'groups' => $this->groupedSettings(),
        ]);
    }
}
