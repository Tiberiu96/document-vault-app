<?php

namespace App\Http\Controllers;

use App\Http\Requests\WorkspaceInvitationRequest;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Services\WebsiteContext;
use App\Services\WorkspaceInvitations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class WorkspaceMemberController extends Controller
{
    public function index(Request $request)
    {
        $workspace = $request->attributes->get('workspace');
        abort_unless($workspace->type === 'business', 404);

        return view('team.members', [...app(WebsiteContext::class)->forPage($request, 'team'), 'section' => 'team', 'workspace' => $workspace,
            'workspaces' => $request->user()->workspaces()->get()->filter(fn ($w) => Gate::allows('view', $w)),
            'members' => $workspace->members()->orderBy('name')->get(),
            'invitations' => Gate::allows('manageMembers', $workspace) ? $workspace->invitations()->whereNull('accepted_at')->whereNull('revoked_at')->latest()->paginate(15) : collect()]);
    }

    public function store(WorkspaceInvitationRequest $request, WorkspaceInvitations $service)
    {
        $service->create($request->user(), $request->attributes->get('workspace'), $request->validated('email'), $request->validated('role'));

        return back()->with('library_status', __('team.sent'));
    }

    public function invitation(Request $request, string $invitation, string $action, WorkspaceInvitations $service)
    {
        $record = WorkspaceInvitation::where('workspace_id', $request->attributes->get('workspace')->id)->findOrFail($invitation);
        $action === 'resend' ? $service->resend($request->user(), $record) : $service->revoke($request->user(), $record);

        return back()->with('library_status', __('team.saved'));
    }

    public function member(Request $request, string $member, WorkspaceInvitations $service)
    {
        $data = $request->validate($request->isMethod('delete') ? ['confirm' => ['required', 'accepted']] : ['role' => ['required', 'in:admin,member,accountant']]);
        $service->changeMember($request->user(), $request->attributes->get('workspace'), User::findOrFail($member), $request->isMethod('delete') ? null : $data['role']);

        return back()->with('library_status', __('team.saved'));
    }
}
