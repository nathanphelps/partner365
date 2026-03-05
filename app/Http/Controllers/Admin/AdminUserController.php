<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ActivityAction;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AdminUserController extends Controller
{
    public function __construct(
        private ActivityLogService $activityLog,
    ) {}

    public function index(): Response
    {
        $users = User::query()
            ->orderByRaw('approved_at IS NOT NULL, approved_at ASC')
            ->orderBy('name')
            ->paginate(25);

        return Inertia::render('admin/Users', [
            'users' => $users,
        ]);
    }

    public function approve(Request $request, User $user): RedirectResponse
    {
        $user->approve($request->user());

        $this->activityLog->log($request->user(), ActivityAction::UserApproved, $user);

        return redirect()->back()->with('success', "User '{$user->name}' approved.");
    }

    public function updateRole(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            abort(403, 'Cannot change your own role.');
        }

        $validated = $request->validate([
            'role' => ['required', Rule::enum(UserRole::class)],
        ]);

        $oldRole = $user->role->value;
        $user->update(['role' => $validated['role']]);

        $this->activityLog->log($request->user(), ActivityAction::UserRoleChanged, $user, [
            'old_role' => $oldRole,
            'new_role' => $validated['role'],
        ]);

        return redirect()->back()->with('success', "Role updated for '{$user->name}'.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            abort(403, 'Cannot delete your own account.');
        }

        $this->activityLog->log($request->user(), ActivityAction::UserDeleted, null, [
            'deleted_user' => $user->name,
            'deleted_email' => $user->email,
        ]);

        $user->delete();

        return redirect()->back()->with('success', "User '{$user->name}' deleted.");
    }
}
