<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminPlanRequest;
use App\Services\Admin\AdminAudit;
use App\Services\PlanLimitsService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminPlanController extends Controller
{
    public function index(PlanLimitsService $limits)
    {
        return view('admin.plans', ['limits' => $limits->free(), 'version' => (int) (DB::table('platform_plan_limits')->where('plan', 'free')->value('version') ?? 0)]);
    }

    public function update(AdminPlanRequest $request)
    {
        try {
            DB::transaction(function () use ($request) {
                $existing = DB::table('platform_plan_limits')->where('plan', 'free')->lockForUpdate()->first();
                if ((int) ($existing->version ?? 0) !== (int) $request->validated('version')) {
                    throw ValidationException::withMessages(['version' => 'Limitele s-au schimbat. Reincarca pagina.']);
                }
                $values = $request->safe()->only(['max_pages', 'max_file_bytes', 'storage_bytes', 'monthly_ocr_pages', 'monthly_ai_calls']);
                $values += ['version' => (int) $request->validated('version') + 1, 'updated_at' => now()];
                if ($existing) {
                    DB::table('platform_plan_limits')->where('plan', 'free')->update($values);
                } else {
                    DB::table('platform_plan_limits')->insert(['plan' => 'free', 'created_at' => now(), ...$values]);
                }
                AdminAudit::record(Auth::guard('admin')->user(), 'plan.limits_updated', 'plan', 'free');
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['version' => 'Limitele s-au schimbat. Reincarca pagina.']);
        }

        return back()->with('admin_status', 'Limitele Free au fost actualizate. Documentele existente nu au fost sterse.');
    }
}
