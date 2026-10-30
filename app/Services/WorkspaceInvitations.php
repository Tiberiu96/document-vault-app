<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Notifications\InvitationMail;
use App\Notifications\WorkspaceAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WorkspaceInvitations
{
    private function role(User $actor, Workspace $workspace, string $role): void
    {
        abort_unless(in_array($role, ['admin', 'member', 'accountant'], true), 422);
        abort_if($role === 'admin' && $workspace->owner_id !== $actor->id, 403);
    }

    public function create(User $actor, Workspace $workspace, string $email, string $role): WorkspaceInvitation
    {
        return DB::transaction(function () use ($actor, $workspace, $email, $role) {
            $workspace = Workspace::lockForUpdate()->findOrFail($workspace->id);
            Gate::forUser($actor)->authorize('manageMembers', $workspace);
            $this->role($actor, $workspace, $role);
            $email = mb_strtolower(trim($email));
            if ($workspace->members()->where('email', $email)->exists()) {
                throw ValidationException::withMessages(['email' => __('team.already_member')]);
            }
            if ($workspace->invitations()->where('email', $email)->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->exists()) {
                throw ValidationException::withMessages(['email' => __('team.already_invited')]);
            }
            app(PlanLimitsService::class)->ensureMemberSeat($workspace);
            $invitation = new WorkspaceInvitation;
            $invitation->forceFill(['workspace_id' => $workspace->id, 'email' => $email, 'role' => $role, 'invited_by' => $actor->id, 'locale' => app()->getLocale()]);
            $this->send($invitation, $workspace);

            return $invitation;
        }, 3);
    }

    private function send(WorkspaceInvitation $invitation, Workspace $workspace): void
    {
        $key = 'workspace-invitations:'.$workspace->id;
        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages(['email' => __('team.rate_limited')]);
        }
        RateLimiter::hit($key, 3600);
        $token = Str::random(64);
        $invitation->forceFill(['token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7), 'last_sent_at' => now()])->save();
        Notification::route('mail', $invitation->email)->notify((new InvitationMail($invitation->id, 'received', $token))->locale($invitation->locale));
        $recipient = User::where('email', $invitation->email)->first();
        $personal = $recipient?->workspaces()->where('type', 'personal')->where('owner_id', $recipient->id)->first();
        if ($personal) {
            $recipient->notify(new WorkspaceAlert($personal->id, 'invitation_received', ['invitation_id' => $invitation->id], 'invitation:'.$invitation->id.':received'));
        }
    }

    public function resend(User $actor, WorkspaceInvitation $invitation): void
    {
        DB::transaction(function () use ($actor, $invitation) {
            $workspace = Workspace::lockForUpdate()->findOrFail($invitation->workspace_id);
            Gate::forUser($actor)->authorize('manageMembers', $workspace);
            $invitation = WorkspaceInvitation::lockForUpdate()->findOrFail($invitation->id);
            $this->role($actor, $workspace, $invitation->role);
            abort_unless(! $invitation->accepted_at && ! $invitation->revoked_at, 409);
            if ($invitation->last_sent_at->gt(now()->subMinute())) {
                throw ValidationException::withMessages(['invitation' => __('team.resend_wait')]);
            }
            app(PlanLimitsService::class)->ensureMemberSeat($workspace, $invitation->id);
            $invitation->invited_by = $actor->id;
            $this->send($invitation, $workspace);
        }, 3);
    }

    public function revoke(User $actor, WorkspaceInvitation $invitation): void
    {
        DB::transaction(function () use ($actor, $invitation) {
            $workspace = Workspace::lockForUpdate()->findOrFail($invitation->workspace_id);
            Gate::forUser($actor)->authorize('manageMembers', $workspace);
            $invitation = WorkspaceInvitation::lockForUpdate()->findOrFail($invitation->id);
            $this->role($actor, $workspace, $invitation->role);
            abort_unless(! $invitation->accepted_at, 409);
            $invitation->forceFill(['revoked_at' => $invitation->revoked_at ?? now()])->save();
        }, 3);
    }

    public function accept(User $user, WorkspaceInvitation $invitation): Workspace
    {
        return DB::transaction(function () use ($user, $invitation) {
            $workspace = Workspace::lockForUpdate()->findOrFail($invitation->workspace_id);
            $invitation = WorkspaceInvitation::lockForUpdate()->findOrFail($invitation->id);
            $user = User::lockForUpdate()->findOrFail($user->id);
            abort_unless($user->hasVerifiedEmail() && hash_equals($invitation->email, $user->email), 403);
            abort_unless($workspace->type === 'business', 403);
            if ($invitation->accepted_at && $invitation->accepted_by === $user->id && $workspace->members()->whereKey($user->id)->exists()) {
                return $workspace;
            }
            abort_unless($invitation->pending(), 410);
            // Un admin eliminat nu poate pastra invitatii care acorda acces ulterior.
            $inviter = $invitation->inviter;
            abort_unless($inviter && Gate::forUser($inviter)->allows('manageMembers', $workspace), 410);
            $this->role($inviter, $workspace, $invitation->role);
            abort_if($workspace->members()->whereKey($user->id)->exists(), 409);
            app(PlanLimitsService::class)->ensureMemberSeat($workspace, $invitation->id);
            $workspace->members()->attach($user->id, ['role' => $invitation->role]);
            $invitation->forceFill(['accepted_at' => now(), 'accepted_by' => $user->id])->save();
            $user->notifications()->where('data->event', 'invitation_received')->where('data->invitation_id', $invitation->id)->whereNull('read_at')->update(['read_at' => now()]);
            $inviter->notify(new WorkspaceAlert($workspace->id, 'invitation_accepted', ['invitation_id' => $invitation->id], 'invitation:'.$invitation->id.':accepted'));
            $inviter->notify((new InvitationMail($invitation->id, 'accepted'))->locale($invitation->locale));

            return $workspace;
        }, 3);
    }

    public function attachIncoming(User $user): void
    {
        $personal = $user->workspaces()->where('type', 'personal')->where('owner_id', $user->id)->first();
        if (! $personal) {
            return;
        }
        foreach (WorkspaceInvitation::where('email', $user->email)->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->get() as $invitation) {
            $user->notify(new WorkspaceAlert($personal->id, 'invitation_received', ['invitation_id' => $invitation->id], 'invitation:'.$invitation->id.':received'));
        }
    }

    public function changeMember(User $actor, Workspace $workspace, User $member, ?string $role): void
    {
        DB::transaction(function () use ($actor, $workspace, $member, $role) {
            $workspace = Workspace::lockForUpdate()->findOrFail($workspace->id);
            Gate::forUser($actor)->authorize('manageMembers', $workspace);
            abort_if($member->id === $workspace->owner_id || $member->id === $actor->id, 403);
            $current = $workspace->members()->whereKey($member->id)->firstOrFail()->pivot->role;
            abort_if($current === 'admin' && $workspace->owner_id !== $actor->id, 403);
            if ($role !== null) {
                $this->role($actor, $workspace, $role);
                $workspace->members()->updateExistingPivot($member->id, ['role' => $role]);
            } else {
                $workspace->members()->detach($member->id);
                $workspace->invitations()->where('invited_by', $member->id)->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
            }
        }, 3);
    }
}
