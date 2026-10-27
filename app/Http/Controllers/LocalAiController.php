<?php

namespace App\Http\Controllers;

use App\Jobs\Ai\RunLocalAiProbe;
use App\Models\Document;
use App\Services\WebsiteContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class LocalAiController extends Controller
{
    public function index(Request $request)
    {
        $workspace = $request->attributes->get('workspace');
        Gate::authorize('manage', [Document::class, $workspace]);
        $request->validate(['run' => ['nullable', 'uuid']]);

        return view('ai.playground', [
            ...app(WebsiteContext::class)->forPage($request, 'documents'), 'section' => 'ai', 'workspace' => $workspace,
            'workspaces' => $request->user()->workspaces()->get()->filter(fn ($w) => Gate::allows('view', $w)),
            'run' => $request->query('run'),
        ]);
    }

    public function store(Request $request)
    {
        $workspace = $request->attributes->get('workspace');
        Gate::authorize('manage', [Document::class, $workspace]);
        $data = $request->validate(['prompt' => ['required', 'string', 'max:500', function ($key, $value, $fail) {
            if (is_string($value) && strlen($value) > 1500) {
                $fail(__('ai.too_long'));
            }
        }]]);
        $run = (string) Str::uuid();
        $job = new RunLocalAiProbe($run, $request->user()->id, $workspace->id, $data['prompt'], app()->getLocale());
        Cache::put($job->key(), Crypt::encryptString(json_encode(['status' => 'pending'])), 600);
        RunLocalAiProbe::dispatch($run, $request->user()->id, $workspace->id, $data['prompt'], app()->getLocale());

        return redirect()->route('ai.playground', ['run' => $run]);
    }

    public function status(Request $request, string $run)
    {
        Gate::authorize('manage', [Document::class, $request->attributes->get('workspace')]);
        $key = 'ai-probe:'.$request->user()->id.':'.$request->attributes->get('workspace')->id.':'.$run;
        $value = Cache::get($key);
        abort_unless(is_string($value), 404);
        $result = json_decode(Crypt::decryptString($value), true, 16, JSON_THROW_ON_ERROR);
        if (($result['status'] ?? '') === 'failed') {
            $result = ['status' => 'failed', 'message' => __('ai.failed')];
        }

        return response()->json($result);
    }
}
