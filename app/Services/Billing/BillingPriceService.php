<?php

namespace App\Services\Billing;

use App\Models\PlatformPlanPrice;
use App\Models\Workspace;
use Illuminate\Validation\ValidationException;

class BillingPriceService
{
    public const CURRENCIES = ['RON', 'EUR', 'HUF'];

    public function defaultCurrency(): string
    {
        $currency = strtoupper((string) config('vault.locales.'.app()->getLocale(), 'RON'));

        return in_array($currency, self::CURRENCIES, true) ? $currency : 'RON';
    }

    public function quote(Workspace $workspace, string $currency, int $additionalSeats = 0): array
    {
        $currency = strtoupper($currency);
        if (! in_array($currency, self::CURRENCIES, true) || $additionalSeats < 0 || $additionalSeats > 1000 || ($workspace->type !== 'business' && $additionalSeats !== 0)) {
            throw ValidationException::withMessages(['currency' => __('plans.billing_unavailable')]);
        }

        $price = PlatformPlanPrice::query()->where('workspace_type', $workspace->type)->where('currency', $currency)->where('enabled', true)->first();
        if (! $price || ($workspace->type === 'business' && $additionalSeats > 0 && $price->extra_seat_amount_minor === null)) {
            throw ValidationException::withMessages(['currency' => __('plans.billing_unavailable')]);
        }

        $seatTotal = $additionalSeats * (int) ($price->extra_seat_amount_minor ?? 0);

        return [
            'workspace_type' => $workspace->type,
            'currency' => $currency,
            'plan_amount_minor' => $price->plan_amount_minor,
            'extra_seat_amount_minor' => $price->extra_seat_amount_minor,
            'additional_member_seats' => $additionalSeats,
            'seat_total_minor' => $seatTotal,
            'total_amount_minor' => $price->plan_amount_minor + $seatTotal,
        ];
    }

    public function enabledCurrencies(Workspace $workspace): array
    {
        return PlatformPlanPrice::query()->where('workspace_type', $workspace->type)->where('enabled', true)->whereIn('currency', self::CURRENCIES)->orderBy('currency')->pluck('currency')->all();
    }

    public function format(int $amountMinor, string $currency): string
    {
        return number_format($amountMinor / 100, 2, app()->getLocale() === 'ro' ? ',' : '.', app()->getLocale() === 'ro' ? '.' : ',').' '.$currency;
    }
}
