<?php

namespace App\Service;

use App\Entity\Role;
use App\Entity\User;
use App\Entity\UserRole;
use Doctrine\ORM\EntityManagerInterface;

/**
 * RoleService
 * 
 * Service for role management
 * Handles role assignment, removal, and queries
 */
class RoleService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RoleHierarchyService $roleHierarchyService
    ) {}

    /**
     * Assign role to user
     */
    public function assignRoleToUser(User $user, Role $role, User $assignedBy): UserRole
    {
        // Check if user has any existing role assignment
        $existingUserRole = $this->entityManager->getRepository(UserRole::class)->findOneBy([
            'user' => $user
        ]);

        if ($existingUserRole) {
            // Update existing role assignment with new role
            $existingUserRole->setRole($role);
            $existingUserRole->setAssignedAt(new \DateTimeImmutable());
            $existingUserRole->setAssignedBy($assignedBy->getEmail());
            $existingUserRole->setIsActive(true);
            $userRole = $existingUserRole;
        } else {
            // Create new UserRole
            $userRole = new UserRole();
            $userRole->setUser($user);
            $userRole->setRole($role);
            $userRole->setAssignedAt(new \DateTimeImmutable());
            $userRole->setAssignedBy($assignedBy->getEmail());
        }

        $this->entityManager->persist($userRole);
        $this->entityManager->flush();

        return $userRole;
    }

    /**
     * Remove role from user
     */
    public function removeUserRole(UserRole $userRole): void
    {
        $this->entityManager->remove($userRole);
        $this->entityManager->flush();
    }

    /**
     * Get role counts for organization
     */
    public function getRoleCountsForOrganization(?\App\Entity\Organization $organization, array $roles): array
    {
        $roleCounts = [];

        foreach ($roles as $role) {
            if ($organization) {
                // Count only users from the same organization
                $count = $this->entityManager->getRepository(UserRole::class)
                    ->createQueryBuilder('ur')
                    ->select('COUNT(ur.id)')
                    ->leftJoin('ur.user', 'u')
                    ->leftJoin('u.organizationMemberships', 'om')
                    ->where('ur.role = :role')
                    ->andWhere('ur.isActive = :active')
                    ->andWhere('om.organization = :organization')
                    ->andWhere('om.isActive = :orgActive')
                    ->andWhere('om.leftAt IS NULL')
                    ->setParameter('role', $role)
                    ->setParameter('active', true)
                    ->setParameter('organization', $organization)
                    ->setParameter('orgActive', true)
                    ->getQuery()
                    ->getSingleScalarResult();
            } else {
                // If user has no organization, return 0
                $count = 0;
            }

            $roleCounts[$role->getCode()] = $count;
        }

        return $roleCounts;
    }

    /**
     * Get users from organization for role management
     */
    public function getUsersFromOrganization(User $currentUser): array
    {
        // Find the current user's organization
        foreach ($currentUser->getOrganizationMemberships() as $membership) {
            if ($membership->isActive() && $membership->getLeftAt() === null) {
                $organization = $membership->getOrganization();
                
                // Get all users from this organization
                $allUsers = $this->entityManager->getRepository(User::class)
                    ->createQueryBuilder('u')
                    ->leftJoin('u.organizationMemberships', 'om')
                    ->where('om.organization = :organization')
                    ->andWhere('om.isActive = :active')
                    ->andWhere('om.leftAt IS NULL')
                    ->setParameter('organization', $organization)
                    ->setParameter('active', true)
                    ->orderBy('u.lastName', 'ASC')
                    ->addOrderBy('u.firstName', 'ASC')
                    ->getQuery()
                    ->getResult();

                // Filter users based on role hierarchy
                return $this->roleHierarchyService->filterUsersByRoleHierarchy($allUsers, $currentUser);
            }
        }

        return [];
    }

    /**
     * Get all roles
     */
    public function getAllRoles(): array
    {
        return $this->entityManager->getRepository(Role::class)->findAll();
    }

    /**
     * Get role by ID
     */
    public function getRoleById(int $id): ?Role
    {
        return $this->entityManager->getRepository(Role::class)->find($id);
    }

    /**
     * Get user role by ID
     */
    public function getUserRoleById(int $id): ?UserRole
    {
        return $this->entityManager->getRepository(UserRole::class)->find($id);
    }
}

