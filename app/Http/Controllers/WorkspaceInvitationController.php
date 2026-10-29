<?php

namespace App\Http\Controllers;

use App\Models\WorkspaceInvitation;
use App\Services\WebsiteContext;
use App\Services\WorkspaceInvitations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class WorkspaceInvitationController extends Controller
{
    private function context(Request $request): array
    {
        $workspaces = $request->user()->workspaces()->get()->filter(fn ($w) => Gate::allows('view', $w));
        $workspace = $workspaces->firstWhere('id', $request->session()->get('active_workspace_id')) ?? $workspaces->first();
        if ($workspace && $workspace->id !== $request->session()->get('active_workspace_id')) {
            $request->session()->put('active_workspace_id', $workspace->id);
        }

        return [...app(WebsiteContext::class)->forPage($request, 'team'), 'section' => 'invitations', 'workspace' => $workspace, 'workspaces' => $workspaces];
    }

    public function link(Request $request, string $invitation, #[\SensitiveParameter] string $token)
    {
        $record = WorkspaceInvitation::findOrFail($invitation);
        abort_unless($record->pending() && hash_equals($record->token_hash, hash('sha256', $token)), 410);
        $request->session()->put('pending_invitation', $record->id);

        return redirect()->route($request->user() ? 'invitations.show' : 'login', $request->user() ? ['invitation' => $record->id] : []);
    }

    public function index(Request $request)
    {
        $invitations = WorkspaceInvitation::with(['workspace', 'inviter'])->where('email', $request->user()->email)->latest()->paginate(15);

        return view('team.invitations', [...$this->context($request), 'invitations' => $invitations]);
    }

    public function show(Request $request, string $invitation)
    {
        $record = WorkspaceInvitation::with(['workspace', 'inviter'])->findOrFail($invitation);
        $recipient = hash_equals($record->email, $request->user()->email);
        $sender = $record->invited_by === $request->user()->id && Gate::allows('view', $record->workspace);
        if (! $recipient && ! $sender) {
            return response()->view('team.wrong-account', $this->context($request), 403);
        }

        return view('team.invitation', [...$this->context($request), 'invitation' => $record, 'recipient' => $recipient]);
    }

    public function accept(Request $request, string $invitation, WorkspaceInvitations $service)
    {
        $record = WorkspaceInvitation::findOrFail($invitation);
        $workspace = $service->accept($request->user(), $record);
        $request->session()->put('active_workspace_id', $workspace->id);
        $request->session()->forget('pending_invitation');

        return redirect()->route('members.index')->with('library_status', __('team.joined'));
    }
}
