<?php

declare(strict_types=1);

namespace App\Domains\Rates\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Rates\ChildCharge;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Inertia\Inertia;

/**
 * Hotel-wide pricing settings: child age bands and hold duration.
 */
final class RateSettingsController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function update(Request $request): RedirectResponse
    {
        $validator = validator($request->all(), [
            'child_age_bands' => ['present', 'array', 'max:3'],
            'child_age_bands.*.from' => ['required', 'integer', 'min:0', 'max:17'],
            'child_age_bands.*.to' => ['required', 'integer', 'min:0', 'max:17'],
            'child_age_bands.*.charge' => ['required', Rule::enum(ChildCharge::class)],
            'hold_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
        ]);

        $validator->after(function (Validator $validator) use ($request): void {
            $bands = (array) $request->input('child_age_bands', []);
            $expectedFrom = 0;

            foreach (array_values($bands) as $index => $band) {
                $from = (int) ($band['from'] ?? -1);
                $to = (int) ($band['to'] ?? -1);

                if ($from !== $expectedFrom || $to < $from) {
                    $validator->errors()->add("child_age_bands.{$index}.from", __('Age bands must start at 0, follow each other without gaps or overlaps, and end after they start.'));

                    return;
                }

                $expectedFrom = $to + 1;
            }
        });

        $data = $validator->validate();

        $settings = HotelSettings::current();
        $settings->update([
            'child_age_bands' => array_values(array_map(fn (array $band): array => [
                'from' => (int) $band['from'],
                'to' => (int) $band['to'],
                'charge' => (string) $band['charge'],
            ], $data['child_age_bands'])),
            'hold_minutes' => (int) $data['hold_minutes'],
        ]);

        Audit::record('rates.settings_updated', 'Pricing settings updated', subject: $this->context->hotel(), properties: [
            'child_age_bands' => $settings->child_age_bands,
            'hold_minutes' => $settings->hold_minutes,
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Pricing settings saved.')]);

        return back();
    }
}
