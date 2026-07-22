<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use RuntimeException;

/**
 * Guards against privilege escalation and self-inflicted lockout on the Staff &
 * Roles screen.
 *
 * The hole this closes: `staff.manage` is, by design, the permission that lets
 * someone edit roles and assign users to them. Unguarded, that single grant is
 * equivalent to every other grant, because the holder can simply tick
 * `scope.all` on their own role and reload. An Admission Officer promoted to
 * "can manage staff" would silently become able to read the institute's entire
 * revenue, least-privilege defeated in two clicks, with no error to notice.
 *
 * The rules below are the standard delegation model:
 *
 *   1. **You cannot grant what you do not hold.** Authority may be passed on
 *      or narrowed, never manufactured. This is the rule that actually stops
 *      escalation; everything else is protection against mistakes.
 *
 *   2. **You cannot change your own role assignment.** Otherwise rule 1 is
 *      trivially bypassed by assigning yourself to a role that already has more.
 *
 *   3. **The last administrator is protected.** The system must always retain
 *      at least one active account holding `staff.manage`, or nobody can ever
 *      administer it again, a bricked install recoverable only from the DB.
 *
 *   4. **You cannot disarm yourself.** Removing `staff.manage` from your own
 *      role, or deactivating your own account, locks you out mid-session.
 *
 * A super admin is deliberately NOT subject to any of this: they are the
 * break-glass identity that repairs an institute which has locked itself out.
 */
class PrivilegeGuard
{
    /**
     * Rule 1 + 4, validate a proposed permission set for a role.
     *
     * @param  list<string>  $keys  permission keys the role would end up with
     *
     * @throws RuntimeException with a message safe to show the user
     */
    public function assertCanSetPermissions(User $actor, ?Role $role, array $keys): void
    {
        $keys = array_values(array_intersect($keys, Permissions::keys()));

        if ($keys === []) {
            throw new RuntimeException('A role must grant at least one permission.');
        }

        // Rule 1: no manufacturing authority you were never given.
        $granting = array_diff($keys, $role?->permissionKeys() ?? []);
        $beyond = array_filter($granting, fn (string $k) => ! $actor->hasPermission($k));

        if ($beyond !== []) {
            $labels = array_map(fn (string $k) => Permissions::label($k), $beyond);

            throw new RuntimeException(
                'You cannot grant permissions you do not hold yourself: '.implode(', ', $labels).'.'
            );
        }

        // Rule 4: do not let the actor strip their own ability to administer.
        if ($role && $role->id === $actor->role_id && ! in_array('staff.manage', $keys, true)) {
            throw new RuntimeException(
                'You cannot remove "Manage staff, roles and permissions" from your own role, you would lock yourself out.'
            );
        }

        // Rule 3: the role that carries the last administrator must keep it.
        if ($role && in_array('staff.manage', $role->permissionKeys(), true) && ! in_array('staff.manage', $keys, true)) {
            $this->assertAnotherAdminSurvives($role, null);
        }
    }

    /**
     * Rule 1 + 2, validate assigning $targetRole to $target.
     *
     * @throws RuntimeException
     */
    public function assertCanAssignRole(User $actor, ?User $target, Role $targetRole): void
    {
        // Rule 2: never re-role yourself. Closes the escalation path where an
        // actor moves their own account into an already-more-powerful role.
        if ($target && $target->is($actor) && $target->role_id !== $targetRole->id) {
            throw new RuntimeException('You cannot change your own role. Ask another administrator.');
        }

        // Rule 1 again, applied to assignment rather than definition: handing
        // someone a role richer than your own is the same escalation by proxy.
        $beyond = array_filter(
            $targetRole->permissionKeys(),
            fn (string $k) => ! $actor->hasPermission($k)
        );

        if ($beyond !== []) {
            $labels = array_map(fn (string $k) => Permissions::label($k), $beyond);

            throw new RuntimeException(
                'That role grants permissions you do not hold, so you cannot assign it: '.implode(', ', $labels).'.'
            );
        }

        // Rule 3: moving the last admin out of an admin role bricks the system.
        if ($target && $target->hasPermission('staff.manage') && ! in_array('staff.manage', $targetRole->permissionKeys(), true)) {
            $this->assertAnotherAdminSurvives(null, $target);
        }
    }

    /**
     * Rule 3 + 4, validate deactivating or deleting a staff account.
     *
     * @throws RuntimeException
     */
    public function assertCanDeactivate(User $actor, User $target): void
    {
        if ($target->is($actor)) {
            throw new RuntimeException('You cannot deactivate your own account.');
        }

        if ($target->hasPermission('staff.manage')) {
            $this->assertAnotherAdminSurvives(null, $target);
        }
    }

    /**
     * At least one active administrator must remain once $excludedUser is gone
     * (or once $excludedRole stops granting `staff.manage`).
     *
     * @throws RuntimeException
     */
    private function assertAnotherAdminSurvives(?Role $excludedRole, ?User $excludedUser): void
    {
        $adminRoleIds = Role::whereHas(
            'permissions',
            fn ($q) => $q->where('permission_key', 'staff.manage')
        )
            ->when($excludedRole, fn ($q) => $q->whereKeyNot($excludedRole->id))
            ->pluck('id');

        $survivors = User::query()
            ->whereIn('role_id', $adminRoleIds)
            ->where('is_active', true)
            ->when($excludedUser, fn ($q) => $q->whereKeyNot($excludedUser->id))
            ->count();

        if ($survivors < 1) {
            throw new RuntimeException(
                'This is the last account that can manage staff. Grant another administrator first, or the portal cannot be administered.'
            );
        }
    }
}
