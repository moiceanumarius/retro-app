<?php

namespace App\Service;

use App\Entity\User;

/**
 * RoleHierarchyService
 * 
 * Service for role hierarchy management
 * Defines and enforces role hierarchy rules
 */
class RoleHierarchyService
{
    /**
     * Role hierarchy definition
     * Higher number = higher privilege level
     */
    private const ROLE_HIERARCHY = [
        'ROLE_MEMBER' => 1,
        'ROLE_FACILITATOR' => 2,
        'ROLE_SUPERVISOR' => 3,
        'ROLE_ADMIN' => 4,
    ];

    /**
     * Get role hierarchy
     */
    public function getRoleHierarchy(): array
    {
        return self::ROLE_HIERARCHY;
    }

    /**
     * Get role level by code
     */
    public function getRoleLevel(string $roleCode): int
    {
        return self::ROLE_HIERARCHY[$roleCode] ?? 0;
    }

    /**
     * Get user's highest role level
     */
    public function getUserRoleLevel(User $user): int
    {
        $currentUserRoleLevel = 0;
        $currentUserRoles = $user->getAllRolesIncludingInherited();

        foreach ($currentUserRoles as $role) {
            if (isset(self::ROLE_HIERARCHY[$role])) {
                $currentUserRoleLevel = max($currentUserRoleLevel, self::ROLE_HIERARCHY[$role]);
            }
        }

        return $currentUserRoleLevel;
    }

    /**
     * Check if user can manage another user based on role hierarchy
     */
    public function canManageUser(User $manager, User $targetUser): bool
    {
        $managerLevel = $this->getUserRoleLevel($manager);
        $targetLevel = $this->getUserRoleLevel($targetUser);

        return $managerLevel > $targetLevel;
    }

    /**
     * Get manageable role codes for user
     * Returns role codes that user can assign (lower than their level)
     */
    public function getManageableRoleCodes(User $user): array
    {
        $userLevel = $this->getUserRoleLevel($user);
        $manageableRoleCodes = [];

        foreach (self::ROLE_HIERARCHY as $roleCode => $level) {
            if ($level < $userLevel) {
                $manageableRoleCodes[] = $roleCode;
            }
        }

        return $manageableRoleCodes;
    }

    /**
     * Get assignable role codes for user
     * Returns role codes that user can view and assign (lower or equal to their level)
     */
    public function getAssignableRoleCodes(User $user): array
    {
        $userLevel = $this->getUserRoleLevel($user);
        $assignableRoleCodes = [];

        foreach (self::ROLE_HIERARCHY as $roleCode => $level) {
            if ($level <= $userLevel) {
                $assignableRoleCodes[] = $roleCode;
            }
        }

        return $assignableRoleCodes;
    }

    /**
     * Filter roles by hierarchy
     * Returns only roles that user can assign (lower than their role)
     */
    public function filterRolesByHierarchy(array $allRoles, User $currentUser): array
    {
        $currentUserRoleLevel = $this->getUserRoleLevel($currentUser);
        $filteredRoles = [];

        foreach ($allRoles as $role) {
            $roleCode = $role->getCode();
            if (isset(self::ROLE_HIERARCHY[$roleCode])) {
                $roleLevel = self::ROLE_HIERARCHY[$roleCode];
                // Only include roles that are lower than current user's role
                if ($roleLevel < $currentUserRoleLevel) {
                    $filteredRoles[] = $role;
                }
            }
        }

        return $filteredRoles;
    }

    /**
     * Filter users by role hierarchy
     * Returns only users that current user can manage
     */
    public function filterUsersByRoleHierarchy(array $users, User $currentUser): array
    {
        $currentUserRoleLevel = $this->getUserRoleLevel($currentUser);
        $filteredUsers = [];

        foreach ($users as $user) {
            // Skip the current user (can't manage themselves)
            if ($user->getId() === $currentUser->getId()) {
                continue;
            }

            $userRoleLevel = $this->getUserRoleLevel($user);

            // Only include users with lower or equal role level
            if ($userRoleLevel <= $currentUserRoleLevel) {
                $filteredUsers[] = $user;
            }
        }

        return $filteredUsers;
    }

    /**
     * Apply role hierarchy filter to query builder
     */
    public function applyRoleHierarchyFilter(\Doctrine\ORM\QueryBuilder $qb, User $currentUser): void
    {
        $manageableRoleCodes = $this->getAssignableRoleCodes($currentUser);

        // Apply filtering - only show users with manageable roles
        if (!empty($manageableRoleCodes)) {
            $qb->andWhere('r.code IN (:manageableRoles)')
               ->setParameter('manageableRoles', $manageableRoleCodes);
        } else {
            // If no manageable roles, return empty result
            $qb->andWhere('1 = 0');
        }

        // Exclude current user from results (can't manage themselves)
        $qb->andWhere('u.id != :currentUserId')
           ->setParameter('currentUserId', $currentUser->getId());
    }
}

