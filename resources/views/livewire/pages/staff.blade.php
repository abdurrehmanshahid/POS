<?php

use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Audit;
use App\Services\PrivilegeGuard;
use App\Support\Permissions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

new class extends Component {
    // ---- Drawer open state (entangled with Alpine) -------------------------
    public bool $roleOpen = false;
    public bool $userOpen = false;

    // ---- Role form ---------------------------------------------------------
    public ?string $roleId = null;   // null => create
    public string $roleName = '';
    /** @var list<string> selected permission keys */
    public array $perms = [];

    // ---- User form ---------------------------------------------------------
    public ?int $userId = null;      // null => create
    public string $fullName = '';
    public string $username = '';
    public string $email = '';
    public string $tempPassword = '';
    public ?string $roleChoice = null;

    public function with(): array
    {
        return [
            'users' => User::with('role.permissions')->orderBy('name')->get(),
            'roles' => Role::with(['permissions', 'users'])
                ->orderByDesc('is_system')->orderBy('name')->get(),
            'grouped' => Permissions::grouped(),
            'totalPerms' => count(Permissions::keys()),
            'canManage' => auth()->user()->can('staff.manage'),
        ];
    }

    // ---- Role actions ------------------------------------------------------
    public function newRole(): void
    {
        abort_unless(auth()->user()->can('staff.manage'), 403);
        $this->reset('roleId', 'roleName', 'perms');
        $this->resetValidation();
        $this->roleOpen = true;
    }

    public function editRole(string $id): void
    {
        abort_unless(auth()->user()->can('staff.manage'), 403);
        $role = Role::with('permissions')->findOrFail($id);
        $this->roleId = $role->id;
        $this->roleName = $role->name;
        $this->perms = $role->permissions->pluck('permission_key')->all();
        $this->resetValidation();
        $this->roleOpen = true;
    }

    public function saveRole(): void
    {
        abort_unless(auth()->user()->can('staff.manage'), 403);

        try {
            $this->validate(
                ['roleName' => ['required', 'string', 'max:80']],
                [],
                ['roleName' => 'name'],
            );
        } catch (ValidationException $e) {
            $this->dispatch('bbt-toast', tone: 'err', title: 'Cannot save role', msg: 'Please fix the highlighted fields.');
            throw $e;
        }

        $keys = array_values(array_intersect($this->perms, Permissions::keys()));
        $existingRole = $this->roleId ? Role::with('permissions')->find($this->roleId) : null;

        // Escalation guard: you cannot grant authority you do not hold, and you
        // cannot strip the system of its last administrator. See PrivilegeGuard.
        try {
            app(PrivilegeGuard::class)->assertCanSetPermissions(auth()->user(), $existingRole, $keys);
        } catch (\RuntimeException $e) {
            $this->addError('perms', $e->getMessage());
            $this->dispatch('bbt-toast', tone: 'err', title: 'Cannot save role', msg: $e->getMessage());

            return;
        }

        if ($this->roleId) {
            // System roles may be renamed but keep is_system / tone.
            $role = Role::findOrFail($this->roleId);
            $role->update(['name' => $this->roleName]);
        } else {
            $role = Role::create([
                'id' => Role::generateId($this->roleName),
                'name' => $this->roleName,
                'tone' => 'navy',
                'is_system' => false,
            ]);
        }

        // Sync role_permissions: delete removed, add new.
        $existing = $role->permissions()->pluck('permission_key')->all();
        $remove = array_diff($existing, $keys);
        $add = array_diff($keys, $existing);
        if ($remove) {
            RolePermission::where('role_id', $role->id)->whereIn('permission_key', $remove)->delete();
        }
        foreach ($add as $key) {
            RolePermission::create(['role_id' => $role->id, 'permission_key' => $key]);
        }

        $this->dispatch('bbt-toast', tone: 'ok', title: $this->roleId ? 'Role updated' : 'Role created', msg: $role->name.' saved.');
        $this->roleOpen = false;
        $this->reset('roleId', 'roleName', 'perms');
    }

    // ---- User actions ------------------------------------------------------
    public function newUser(): void
    {
        abort_unless(auth()->user()->can('staff.manage'), 403);
        $this->reset('userId', 'fullName', 'username', 'email', 'tempPassword', 'roleChoice');
        $this->resetValidation();
        $this->userOpen = true;
    }

    public function editUser(int $id): void
    {
        abort_unless(auth()->user()->can('staff.manage'), 403);
        $user = User::findOrFail($id);
        $this->userId = $user->id;
        $this->fullName = $user->name;
        $this->username = $user->username;
        $this->email = $user->email;
        $this->tempPassword = '';
        $this->roleChoice = $user->role_id;
        $this->resetValidation();
        $this->userOpen = true;
    }

    public function saveUser(): void
    {
        abort_unless(auth()->user()->can('staff.manage'), 403);

        // Normalise so uniqueness is checked against the stored form.
        $this->username = strtolower(trim($this->username));
        $this->email = strtolower(trim($this->email));

        try {
            $this->validate([
                'fullName' => ['required', 'string', 'max:120'],
                'username' => ['required', 'string', 'max:60', Rule::unique('users', 'username')->ignore($this->userId)],
                'email' => ['required', 'string', 'email', 'max:160', Rule::unique('users', 'email')->ignore($this->userId)],
                'roleChoice' => ['required', 'string', Rule::exists('roles', 'id')],
                'tempPassword' => $this->userId ? ['nullable', 'string', 'min:6'] : ['required', 'string', 'min:6'],
            ], [], [
                'fullName' => 'full name',
                'roleChoice' => 'role',
                'tempPassword' => 'temporary password',
            ]);
        } catch (ValidationException $e) {
            $this->dispatch('bbt-toast', tone: 'err', title: 'Cannot save user', msg: 'Please fix the highlighted fields.');
            throw $e;
        }

        $target = $this->userId ? User::find($this->userId) : null;
        $targetRole = Role::with('permissions')->findOrFail($this->roleChoice);

        // Escalation guard: you cannot hand out a role richer than your own, and
        // you cannot re-role yourself into one. See PrivilegeGuard.
        try {
            app(PrivilegeGuard::class)->assertCanAssignRole(auth()->user(), $target, $targetRole);
        } catch (\RuntimeException $e) {
            $this->addError('roleChoice', $e->getMessage());
            $this->dispatch('bbt-toast', tone: 'err', title: 'Cannot save user', msg: $e->getMessage());

            return;
        }

        $data = [
            'name' => $this->fullName,
            'username' => $this->username,
            'email' => $this->email,
            'role_id' => $this->roleChoice,
        ];

        if ($this->userId) {
            $user = $target;
            $roleChanged = $user->role_id !== $this->roleChoice;

            if (filled($this->tempPassword)) {
                $data['password'] = Hash::make($this->tempPassword);
                // An admin-set password is temporary by definition, the holder
                // must replace it with one only they know at next sign-in.
                $data['must_reset_password'] = true;
            }
            $user->update($data);

            if ($roleChanged) {
                Audit::record('Role changed', auth()->user(), [
                    'subject' => $user,
                    'subject_label' => $user->name,
                    'field' => 'role_id',
                    'old_value' => $target->getOriginal('role_id') ?? '',
                    'new_value' => $this->roleChoice,
                ]);
            }
            if (filled($this->tempPassword)) {
                Audit::record('Password reset by admin', auth()->user(), [
                    'subject' => $user,
                    'subject_label' => $user->name,
                    'field' => 'password',
                ]);
            }
        } else {
            $data['password'] = Hash::make($this->tempPassword);
            $data['must_reset_password'] = true;
            $data['is_active'] = true;
            $user = User::create($data);

            Audit::record('Staff account created', auth()->user(), [
                'subject' => $user,
                'subject_label' => $user->name,
                'new_value' => $targetRole->name,
            ]);
        }

        $this->dispatch('bbt-toast', tone: 'ok', title: $this->userId ? 'User updated' : 'User created', msg: $user->name.' saved.');
        $this->userOpen = false;
        $this->reset('userId', 'fullName', 'username', 'email', 'tempPassword', 'roleChoice');
    }
}; ?>

<div class="container-app anim-fade">
    {{-- Header --}}
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:22px">
        <div>
            <h1 style="font-size:var(--fs-xl);font-weight:800;letter-spacing:-.02em;color:var(--ink);margin:0">Staff &amp; roles</h1>
            <p style="font-size:var(--fs-sm);color:var(--muted);margin:4px 0 0">Accounts, roles and the permissions each one grants.</p>
        </div>
        @if ($canManage)
            <div style="display:flex;gap:10px;flex:0 0 auto">
                <button class="btn btn-ghost" wire:click="newRole"><x-icon name="plus" :size="16" /> New role</button>
                <button class="btn btn-accent" wire:click="newUser"><x-icon name="plus" :size="16" /> New user</button>
            </div>
        @endif
    </div>

    {{-- Panel A · Staff accounts -------------------------------------------- --}}
    <div class="panel" style="margin-bottom:22px">
        <div class="panel-head"><x-icon name="staff" :size="18" style="color:var(--navy2)" /><h3 class="panel-title">Staff accounts</h3></div>
        <div class="scroll-x">
            <table class="table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Role</th>
                        <th>Data scope</th>
                        @if ($canManage)<th class="right">Actions</th>@endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $u)
                        <tr>
                            <td>
                                <div style="display:flex;align-items:center;gap:11px">
                                    <x-ui.avatar :name="$u->name" :variant="$u->role && $u->role->tone === 'orange' ? 'orange' : 'navy'" :size="34" />
                                    <div>
                                        <div style="font-weight:600;color:var(--ink)">{{ $u->name }}</div>
                                        <div style="font-size:var(--fs-xs);color:var(--muted)">{{ $u->email }}</div>
                                    </div>
                                </div>
                            </td>
                            <td><x-ui.pill :tone="$u->role?->tone ?? 'iris'">{{ $u->roleLabel() }}</x-ui.pill></td>
                            <td style="color:var(--ink2)">
                                {{ $u->role?->hasPermission('scope.all') ? 'All students (institute-wide)' : 'Own-enrolled students only' }}
                            </td>
                            @if ($canManage)
                                <td class="right">
                                    <button class="btn btn-ghost btn-sm" wire:click="editUser({{ $u->id }})"><x-icon name="edit" :size="14" /> Edit</button>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ $canManage ? 4 : 3 }}" class="empty-state">No staff accounts yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Panel B · Roles ----------------------------------------------------- --}}
    <div class="panel" style="margin-bottom:22px">
        <div class="panel-head"><x-icon name="lock" :size="18" style="color:var(--navy2)" /><h3 class="panel-title">Roles</h3></div>
        @foreach ($roles as $role)
            <div style="display:flex;align-items:center;gap:14px;padding:14px 22px;{{ $loop->first ? '' : 'border-top:1px solid var(--surface3)' }}">
                <x-ui.pill :tone="$role->tone">{{ $role->name }}</x-ui.pill>
                <div style="flex:1;font-size:var(--fs-xs);color:var(--muted)">
                    <span class="tnum">{{ $role->permissions->count() }}</span> of <span class="tnum">{{ $totalPerms }}</span> permissions ·
                    <span class="tnum">{{ $role->users->count() }}</span> user(s)
                    @if ($role->is_system)<span style="color:var(--faint)"> · system</span>@endif
                </div>
                @if ($canManage)
                    <button class="btn btn-ghost btn-sm" wire:click="editRole('{{ $role->id }}')"><x-icon name="edit" :size="14" /> Edit</button>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Panel C · Access matrix --------------------------------------------- --}}
    <div class="panel">
        <div class="panel-head"><x-icon name="check-circle" :size="18" style="color:var(--paid)" /><h3 class="panel-title">Access matrix</h3></div>
        <div class="scroll-x">
            <table class="table">
                <thead>
                    <tr>
                        <th>Permission</th>
                        @foreach ($roles as $role)<th style="text-align:center">{{ $role->name }}</th>@endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($grouped as $group => $items)
                        <tr>
                            <td colspan="{{ count($roles) + 1 }}" style="background:var(--surface2);font-size:var(--fs-2xs);font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--faint)">{{ $group }}</td>
                        </tr>
                        @foreach ($items as $perm)
                            <tr>
                                <td style="color:var(--ink2)">{{ $perm['label'] }}</td>
                                @foreach ($roles as $role)
                                    <td style="text-align:center">
                                        @if ($role->hasPermission($perm['key']))
                                            <x-icon name="check" :size="15" style="color:var(--paid)" />
                                        @else
                                            <x-icon name="minus-circle" :size="14" style="color:var(--faint)" />
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="display:flex;align-items:center;gap:6px;padding:12px 22px;border-top:1px solid var(--border);font-size:var(--fs-xs);color:var(--muted)">
            <x-icon name="check" :size="13" style="color:var(--paid)" /> granted
            <span style="color:var(--border2)">|</span>
            <x-icon name="minus-circle" :size="13" style="color:var(--faint)" /> hidden
        </div>
    </div>

    {{-- ==================================================================== --}}
    {{-- Role form drawer                                                     --}}
    {{-- ==================================================================== --}}
    @if ($canManage)
        <div x-data="{ open: @entangle('roleOpen') }">
            <template x-if="open">
                <div>
                    <div class="drawer-backdrop" @click="open=false"></div>
                    <div class="drawer">
                        <div class="drawer-head">
                            <div class="drawer-head-icon"><x-icon name="lock" :size="19" /></div>
                            <div class="drawer-head-text">
                                <div class="drawer-head-title">{{ $roleId ? 'Edit role' : 'New role' }}</div>
                                <div class="drawer-head-sub">Choose the permissions this role grants.</div>
                            </div>
                            <button class="btn-icon btn-icon-plain" @click="open=false" title="Close"><x-icon name="x" :size="17" /></button>
                        </div>
                        <div class="drawer-body">
                            <div style="margin-bottom:18px">
                                <label class="label">Role name</label>
                                <input type="text" class="input @error('roleName') is-error @enderror" wire:model="roleName" placeholder="e.g. Finance Officer">
                                @error('roleName')<span class="field-error">{{ $message }}</span>@enderror
                            </div>

                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
                                <span class="label" style="margin:0">Permissions</span>
                                <span class="tnum" style="font-size:var(--fs-xs);font-weight:700;color:var(--muted)">{{ count($perms) }} of {{ $totalPerms }} selected</span>
                            </div>
                            @error('perms')<span class="field-error" style="margin-bottom:8px">{{ $message }}</span>@enderror

                            @foreach ($grouped as $group => $items)
                                @php $gcount = count(array_intersect(array_column($items, 'key'), $perms)); @endphp
                                <div style="border:1px solid var(--border);border-radius:12px;margin-bottom:12px;overflow:hidden">
                                    <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:var(--surface2)">
                                        <span style="font-size:var(--fs-2xs);font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--ink2)">{{ $group }}</span>
                                        <span class="tnum" style="font-size:var(--fs-2xs);font-weight:700;color:var(--muted)">{{ $gcount }}/{{ count($items) }}</span>
                                    </div>
                                    <div style="padding:4px 14px">
                                        @foreach ($items as $perm)
                                            <label style="display:flex;align-items:center;gap:10px;padding:9px 0;cursor:pointer;font-size:var(--fs-sm);color:var(--ink)">
                                                <input type="checkbox" wire:model.live="perms" value="{{ $perm['key'] }}">
                                                <span>{{ $perm['label'] }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="drawer-foot">
                            <button class="btn btn-ghost" @click="open=false" style="flex:1">Cancel</button>
                            <button class="btn btn-accent" wire:click="saveRole" style="flex:1">{{ $roleId ? 'Save role' : 'Create role' }}</button>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        {{-- ================================================================ --}}
        {{-- User form drawer                                                 --}}
        {{-- ================================================================ --}}
        <div x-data="{ open: @entangle('userOpen') }">
            <template x-if="open">
                <div>
                    <div class="drawer-backdrop" @click="open=false"></div>
                    <div class="drawer">
                        <div class="drawer-head">
                            <div class="drawer-head-icon"><x-icon name="users" :size="19" /></div>
                            <div class="drawer-head-text">
                                <div class="drawer-head-title">{{ $userId ? 'Edit user' : 'New user' }}</div>
                                <div class="drawer-head-sub">Staff login and assigned role.</div>
                            </div>
                            <button class="btn-icon btn-icon-plain" @click="open=false" title="Close"><x-icon name="x" :size="17" /></button>
                        </div>
                        <div class="drawer-body">
                            <div style="margin-bottom:16px">
                                <label class="label">Full name</label>
                                <input type="text" class="input @error('fullName') is-error @enderror" wire:model="fullName" placeholder="e.g. Ansar Ali">
                                @error('fullName')<span class="field-error">{{ $message }}</span>@enderror
                            </div>

                            <div class="grid-2" style="margin-bottom:16px">
                                <div>
                                    <label class="label">Username</label>
                                    <input type="text" class="input @error('username') is-error @enderror" wire:model="username" autocomplete="off" placeholder="e.g. adminansar">
                                    @error('username')<span class="field-error">{{ $message }}</span>@enderror
                                </div>
                                <div>
                                    <label class="label">Email</label>
                                    <input type="email" class="input @error('email') is-error @enderror" wire:model="email" autocomplete="off" placeholder="name@bbt.edu.pk">
                                    @error('email')<span class="field-error">{{ $message }}</span>@enderror
                                </div>
                            </div>

                            <div style="margin-bottom:18px">
                                <label class="label">Temporary password</label>
                                <input type="password" class="input @error('tempPassword') is-error @enderror" wire:model="tempPassword" autocomplete="new-password"
                                       placeholder="{{ $userId ? 'Leave blank to keep current' : 'Min 6 characters' }}">
                                @error('tempPassword')<span class="field-error">{{ $message }}</span>@enderror
                                <p style="font-size:var(--fs-2xs);color:var(--muted);margin:5px 0 0">
                                    {{ $userId ? 'Leave blank to keep the existing password.' : 'The user must reset this on first login.' }}
                                </p>
                            </div>

                            <label class="label">Role</label>
                            <div style="display:flex;flex-direction:column;gap:8px">
                                @foreach ($roles as $role)
                                    <label style="display:flex;align-items:center;gap:12px;padding:12px 14px;border:1.5px solid var(--border2);border-radius:11px;cursor:pointer">
                                        <input type="radio" wire:model="roleChoice" value="{{ $role->id }}">
                                        <x-ui.pill :tone="$role->tone">{{ $role->name }}</x-ui.pill>
                                        <span style="flex:1"></span>
                                        <span class="tnum" style="font-size:var(--fs-xs);font-weight:700;color:var(--muted)">{{ $role->permissions->count() }} permissions</span>
                                    </label>
                                @endforeach
                            </div>
                            @error('roleChoice')<span class="field-error">{{ $message }}</span>@enderror
                        </div>
                        <div class="drawer-foot">
                            <button class="btn btn-ghost" @click="open=false" style="flex:1">Cancel</button>
                            <button class="btn btn-accent" wire:click="saveUser" style="flex:1">{{ $userId ? 'Save user' : 'Create user' }}</button>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    @endif
</div>
