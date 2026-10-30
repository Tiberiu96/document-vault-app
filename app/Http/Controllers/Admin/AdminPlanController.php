<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminPlanRequest;
use App\Models\Workspace;
use App\Services\Admin\AdminAudit;
use App\Services\PlanLimitsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminPlanController extends Controller
{
    public function index(Request $request, PlanLimitsService $limits)
    {
        $plan = $request->validate(['plan' => ['sometimes', 'in:free,pro']])['plan'] ?? 'free';
        $definition = $limits->definition($plan);

        return view('admin.plans', ['plan' => $plan, 'limits' => $definition['limits'], 'version' => $definition['version'], 'enabled' => $definition['enabled'], 'extraMemberPriceBani' => $definition['extra_member_price_bani']]);
    }

    public function update(AdminPlanRequest $request)
    {
        try {
            DB::transaction(function () use ($request) {
                $plan = $request->validated('plan', 'free');
                $existing = DB::table('platform_plan_limits')->where('plan', $plan)->lockForUpdate()->first();
                if ((int) ($existing->version ?? 0) !== (int) $request->validated('version')) {
                    throw ValidationException::withMessages(['version' => 'Limitele s-au schimbat. Reincarca pagina.']);
                }
                $values = $request->safe()->only(['max_pages', 'max_file_bytes', 'storage_bytes', 'monthly_ocr_pages', 'monthly_ai_calls']);
                if ($plan === 'pro' && $request->exists('extra_member_price_bani')) {
                    $values['extra_member_price_bani'] = $request->validated('extra_member_price_bani');
                }
                $values['enabled'] = $plan === 'free' || $request->boolean('enabled');
                $values += ['version' => (int) $request->validated('version') + 1, 'updated_at' => now()];
                if ($existing) {
                    DB::table('platform_plan_limits')->where('plan', $plan)->update($values);
                } else {
                    DB::table('platform_plan_limits')->insert(['plan' => $plan, 'created_at' => now(), ...$values]);
                }
                AdminAudit::record(Auth::guard('admin')->user(), 'plan.limits_updated', 'plan', $plan);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['version' => 'Limitele s-au schimbat. Reincarca pagina.']);
        }

        return back()->with('admin_status', 'Planul a fost actualizat. Documentele si consumul existent au fost pastrate.');
    }

    public function workspace(Request $request, Workspace $workspace, PlanLimitsService $limits)
    {
        $filters = $request->validate(['kind' => ['nullable', 'in:ocr,ai'], 'status' => ['nullable', 'in:queued,running,succeeded,failed']]);

        return view('admin.workspace-plan', ['workspace' => $workspace, 'usage' => $limits->usage($workspace), 'proEnabled' => $limits->definition('pro')['enabled'], 'extraMemberPriceBani' => $limits->definition('pro')['extra_member_price_bani'],
            'processing' => DB::table('processing_runs')->where('workspace_id', $workspace->id)->where('period', now('UTC')->startOfMonth()->toDateString())
                ->when($filters['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
                ->selectRaw('kind, provider, status, SUM(units) as units, SUM(attempts) as attempts')->groupBy('kind', 'provider', 'status')->orderBy('kind')->orderBy('provider')->orderBy('status')->paginate(20)->withQueryString(), 'filters' => $filters]);
    }

    public function assign(Request $request, Workspace $workspace, PlanLimitsService $limits)
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:0'], 'plan' => ['required', 'in:free,pro'], 'additional_member_seats' => ['sometimes', 'integer', 'min:0', 'max:1000'], 'expires_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'], 'confirm' => ['required', 'accepted']]);
        DB::transaction(function () use ($workspace, $data, $limits) {
            $workspace = Workspace::lockForUpdate()->findOrFail($workspace->id);
            if ($workspace->plan_version !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => 'Planul spatiului s-a schimbat. Reincarca pagina.']);
            }
            if ($data['plan'] === 'pro' && ! $limits->definition('pro')['enabled']) {
                throw ValidationException::withMessages(['plan' => 'Configureaza si activeaza sablonul Pro inainte de atribuire.']);
            }
            $extra = (int) ($data['additional_member_seats'] ?? $workspace->additional_member_seats);
            if ($extra > 0 && ($workspace->type !== 'business' || ($extra !== $workspace->additional_member_seats && $data['plan'] !== 'pro') || ($data['plan'] === 'pro' && $limits->definition('pro')['extra_member_price_bani'] === null))) {
                throw ValidationException::withMessages(['additional_member_seats' => 'Locurile suplimentare necesita un spatiu business si un pret configurat in planul Pro / Business.']);
            }
            $seatsChanged = $extra !== $workspace->additional_member_seats;
            $workspace->forceFill(['plan_code' => $data['plan'], 'additional_member_seats' => $extra, 'plan_version' => $workspace->plan_version + 1,
                'plan_expires_at' => $data['plan'] === 'pro' && ! empty($data['expires_on']) ? CarbonImmutable::parse($data['expires_on'], 'UTC')->addDay()->startOfDay() : null])->save();
            if ($seatsChanged) {
                AdminAudit::record(Auth::guard('admin')->user(), 'plan.member_seats_updated', 'workspace', $workspace->id);
            }
            AdminAudit::record(Auth::guard('admin')->user(), 'plan.assigned_'.$data['plan'], 'workspace', $workspace->id);
        }, 3);

        return back()->with('admin_status', 'Plan atribuit manual. Consumul nu a fost resetat; nu s-a efectuat nicio plata.');
    }
}
