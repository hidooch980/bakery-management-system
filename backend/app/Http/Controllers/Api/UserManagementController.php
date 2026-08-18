<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\NotAGuessablePassword;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * Admin-only staff management. Account creation is deliberately restricted
 * to this controller — there is no public registration endpoint.
 */
class UserManagementController extends Controller
{
    use ApiResponse;

    public const ASSIGNABLE_ROLES = ['admin', 'dough_maker', 'chane_gir', 'shater', 'seller'];

    /** Persian names for the roles, so clients never show a raw slug. */
    public const ROLE_LABELS = [
        'admin' => 'مدیر',
        'dough_maker' => 'خمیرگیر',
        'chane_gir' => 'چانه‌گیر',
        'shater' => 'شاطر',
        'seller' => 'فروشنده',
    ];

    public function index(Request $request): JsonResponse
    {
        $users = User::ofCurrentBakery()->with('roles:id,name')
            ->when($request->query('role'), fn ($q, $role) => $q->role($role))
            ->latest()
            ->paginate(20);

        return $this->success($users);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:8', new NotAGuessablePassword],
            'role' => ['required', Rule::in(self::ASSIGNABLE_ROLES)],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
            'is_active' => true,
        ]);

        $user->syncRoles([$data['role']]);

        return $this->success($user->load('roles:id,name'), 'کاربر ساخته شد.', 201);
    }

    public function show(User $user): JsonResponse
    {
        return $this->success($user->load('roles:id,name'));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', Rule::unique('users')->ignore($user->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20', Rule::unique('users')->ignore($user->id)],
            'password' => ['sometimes', 'string', 'min:8', new NotAGuessablePassword],
            'is_active' => ['sometimes', 'boolean'],
            'role' => ['sometimes', Rule::in(self::ASSIGNABLE_ROLES)],
        ]);

        $user->update(collect($data)->except('role')->toArray());

        if (isset($data['role'])) {
            $user->syncRoles([$data['role']]);
        }

        return $this->success($user->fresh()->load('roles:id,name'), 'کاربر به‌روزرسانی شد.');
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return $this->error('نمی‌توانید حساب خودتان را حذف کنید.', 422);
        }

        $user->delete();

        return $this->success(null, 'کاربر حذف شد.');
    }

    public function toggleActive(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return $this->error('نمی‌توانید حساب خودتان را غیرفعال کنید.', 422);
        }

        $user->update(['is_active' => ! $user->is_active]);
        $user->tokens()->delete();

        return $this->success(
            ['is_active' => $user->is_active],
            $user->is_active ? 'کاربر فعال شد.' : 'کاربر غیرفعال شد.'
        );
    }

    public function roles(): JsonResponse
    {
        return $this->success(
            Role::pluck('name')->map(fn (string $name) => [
                'value' => $name,
                'label' => self::ROLE_LABELS[$name] ?? $name,
            ])->values()
        );
    }
}
