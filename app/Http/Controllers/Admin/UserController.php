<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Users/Index', [
            'users' => User::query()
                ->with('roles')
                ->orderBy('name')
                ->get()
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->roles->first()?->name,
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Users/Form', [
            'user' => null,
            'roles' => array_keys(config('roles.available')),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        $user->assignRole($data['role']);

        return to_route('admin.users.index')->with('success', __('admin.users.created'));
    }

    public function edit(User $user): Response
    {
        return Inertia::render('Admin/Users/Form', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->roles->first()?->name,
            ],
            'roles' => array_keys(config('roles.available')),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();

        if ($user->hasRole('admin') && $data['role'] !== 'admin' && $this->isLastAdmin($user)) {
            throw ValidationException::withMessages(['role' => __('admin.users.cannot_demote_last_admin')]);
        }

        $user->update([
            'name' => $data['name'],
            'email' => $data['email'],
            ...($data['password'] ? ['password' => Hash::make($data['password'])] : []),
        ]);

        $user->syncRoles([$data['role']]);

        return to_route('admin.users.index')->with('success', __('admin.users.updated'));
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->withErrors(['user' => __('admin.users.cannot_delete_self')]);
        }

        if ($user->hasRole('admin') && $this->isLastAdmin($user)) {
            return back()->withErrors(['user' => __('admin.users.cannot_delete_last_admin')]);
        }

        $user->delete();

        return to_route('admin.users.index')->with('success', __('admin.users.deleted'));
    }

    private function isLastAdmin(User $user): bool
    {
        return User::role('admin')->where('id', '!=', $user->id)->doesntExist();
    }
}
