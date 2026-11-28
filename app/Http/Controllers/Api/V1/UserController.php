<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use App\Models\Department;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    /**
     * Display a listing of system users (Superadmin only).
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::with(['role', 'department']);
        if ($search = trim($request->input('search', ''))) {
            $query->where(function ($users) use ($search) {
                $users->whereLike('name', "%{$search}%")
                    ->orWhereLike('username', "%{$search}%")
                    ->orWhereLike('email', "%{$search}%")
                    ->orWhereHas('role', fn($role) => $role->whereLike('name', "%{$search}%"));
            });
        }
        foreach (['status', 'role_id', 'department_id'] as $filter) {
            if ($value = $request->input($filter)) $query->where($filter, $value);
        }
        $users = $query->orderByDesc('id')->paginate(max(1, min(100, $request->integer('per_page', 25))));

        $roles = Role::orderBy('name')->get();
        $departments = Department::orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => $users,
            'meta' => [
                'roles' => $roles,
                'departments' => $departments,
            ]
        ]);
    }

    /**
     * Create a new user account (Superadmin only).
     * Marked with is_first_login = true to require password reset and profile completion.
     */
    public function store(Request $request): JsonResponse
    {
        $departmentRequired = Role::find($request->integer('role_id'))?->code === 'DEPT_ADMIN';
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:50|unique:users,username',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8',
            'role_id' => ['required', Rule::exists('roles', 'id')->where(fn ($query) => $query->where('code', '!=', 'SUPER_ADMIN'))],
            'department_id' => [Rule::requiredIf($departmentRequired), 'nullable', 'exists:departments,id'],
            'designation' => 'nullable|string|max:255',
            'grade_level' => 'nullable|string|max:50',
            'status' => ['required', Rule::in(['active', 'suspended'])],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'username' => strtolower(trim($validated['username'])),
            'email' => strtolower(trim($validated['email'])),
            'password' => Hash::make($validated['password']),
            'role_id' => $validated['role_id'],
            'department_id' => $validated['department_id'] ?? null,
            'designation' => $validated['designation'] ?? null,
            'grade_level' => $validated['grade_level'] ?? null,
            'status' => $validated['status'],
            'is_first_login' => true, // Enforce password change and profile verification
        ]);

        $user->load(['role', 'department']);

        AuditLogService::log(
            action: 'USER_CREATED',
            module: 'USER_MANAGEMENT',
            recordType: User::class,
            recordId: $user->id,
            previousValues: null,
            newValues: [
                'username' => $user->username,
                'email' => $user->email,
                'role' => $user->role?->code,
                'status' => $user->status,
            ],
            description: "User account {$user->name} ({$user->username}) created with role {$user->role?->name} by {$request->user()->name}."
        );

        return response()->json([
            'success' => true,
            'message' => "User account for {$user->name} created successfully.",
            'data' => $user,
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        if ($user->role?->code === 'SUPER_ADMIN') {
            return response()->json(['success' => false, 'message' => 'Super administrator accounts cannot be modified here.'], 403);
        }

        $validated = $request->validate([
            'role_id' => ['sometimes', 'required', Rule::exists('roles', 'id')->where(fn ($query) => $query->where('code', '!=', 'SUPER_ADMIN'))],
            'status' => ['sometimes', 'required', Rule::in(['active', 'suspended'])],
            'password' => 'sometimes|required|string|min:8|max:255',
            'department_id' => 'sometimes|nullable|exists:departments,id',
        ]);
        $roleId = $validated['role_id'] ?? $user->role_id;
        $departmentId = array_key_exists('department_id', $validated) ? $validated['department_id'] : $user->department_id;
        if (Role::find($roleId)?->code === 'DEPT_ADMIN' && !$departmentId) {
            throw ValidationException::withMessages(['department_id' => 'A department administrator must be assigned to a department.']);
        }

        DB::transaction(function () use ($validated, $user) {
            $previous = $user->only(['role_id', 'status', 'department_id']);
            $passwordChanged = array_key_exists('password', $validated);
            if ($passwordChanged) {
                $validated['password'] = Hash::make($validated['password']);
                $validated['is_first_login'] = true;
            }
            $user->fill($validated);
            $changed = $user->isDirty(['role_id', 'status', 'password', 'department_id']);
            $user->save();
            if ($changed) {
                $user->tokens()->delete();
                AuditLogService::log(
                    action: 'USER_UPDATED',
                    module: 'USER_MANAGEMENT',
                    recordType: User::class,
                    recordId: $user->id,
                    previousValues: $previous,
                    newValues: $user->only(['role_id', 'status', 'department_id']) + ['password_reset' => $passwordChanged],
                    description: $this->describeUserChange($user, $previous, $passwordChanged)
                );
            }
        });

        return response()->json(['success' => true, 'message' => 'User account updated.', 'data' => $user->load(['role', 'department'])]);
    }

    private function describeUserChange(User $user, array $previous, bool $passwordChanged): string
    {
        $changes = [];
        if ($previous['status'] !== $user->status) {
            $changes[] = "status changed from {$previous['status']} to {$user->status}";
        }
        if ((int) $previous['role_id'] !== (int) $user->role_id) {
            $changes[] = 'role changed from ' . (Role::find($previous['role_id'])?->name ?? 'none') . ' to ' . ($user->role()->value('name') ?? 'none');
        }
        if ((int) $previous['department_id'] !== (int) $user->department_id) {
            $changes[] = 'department assignment changed';
        }
        if ($passwordChanged) {
            $changes[] = 'password reset (change required at next sign-in)';
        }

        return "User account {$user->name} ({$user->username}) updated by " . (auth()->user()?->name ?? 'the system') . ': ' . implode('; ', $changes) . '. Active sessions were signed out.';
    }
}
