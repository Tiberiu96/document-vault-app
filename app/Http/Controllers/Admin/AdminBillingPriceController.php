<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformPlanPrice;
use App\Services\Admin\AdminAudit;
use App\Services\Billing\BillingPriceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminBillingPriceController extends Controller
{
    public function update(Request $request)
    {
        $data = $request->validate([
            'workspace_type' => ['required', Rule::in(['personal', 'business'])],
            'currency' => ['required', Rule::in(BillingPriceService::CURRENCIES)],
            'plan_amount_minor' => ['required', 'integer', 'min:1', 'max:100000000'],
            'extra_seat_amount_minor' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'enabled' => ['sometimes', 'boolean'],
            'version' => ['required', 'integer', 'min:0'],
        ]);
        if ($data['workspace_type'] === 'business' && $data['extra_seat_amount_minor'] === null) {
            throw ValidationException::withMessages(['extra_seat_amount_minor' => 'Pretul unui loc suplimentar este obligatoriu pentru Business.']);
        }

        DB::transaction(function () use ($request, $data): void {
            $price = PlatformPlanPrice::lockForUpdate()->firstOrNew(['workspace_type' => $data['workspace_type'], 'currency' => $data['currency']]);
            if ((int) $price->version !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => 'Pretul s-a schimbat. Reincarca pagina.']);
            }
            $price->fill([
                'plan_amount_minor' => $data['plan_amount_minor'],
                'extra_seat_amount_minor' => $data['workspace_type'] === 'business' ? $data['extra_seat_amount_minor'] : null,
                'enabled' => $request->boolean('enabled'),
                'version' => $price->version + 1,
            ])->save();
            AdminAudit::record(Auth::guard('admin')->user(), 'billing.price_updated', 'plan_price', $price->id);
        }, 3);

        return back()->with('admin_status', 'Pretul comercial a fost actualizat. Abonamentele existente nu au fost modificate.');
    }
}
