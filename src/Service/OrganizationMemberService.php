<?php

namespace App\Service;

use App\Entity\Organization;
use App\Entity\OrganizationMember;
use App\Entity\User;
use App\Entity\Role;
use App\Entity\UserRole;
use App\Entity\Team;
use App\Entity\TeamMember;
use App\Repository\OrganizationMemberRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * OrganizationMemberService
 * 
 * Service for managing organization members
 * Handles member addition, removal, reactivation and role management
 */
class OrganizationMemberService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OrganizationMemberRepository $organizationMemberRepository
    ) {}

    /**
     * Add user to organization
     */
    public function addMemberToOrganization(
        User $user,
        Organization $organization,
        string $role,
        User $invitedBy
    ): OrganizationMember {
        // Check if user is already a member or was a member before
        $existingMember = $this->organizationMemberRepository->findOneBy([
            'user' => $user,
            'organization' => $organization
        ]);

        if ($existingMember) {
            // Reactivate former member
            return $this->reactivateMember($existingMember, $role, $invitedBy);
        }

        // Create new organization member
        $member = new OrganizationMember();
        $member->setUser($user);
        $member->setOrganization($organization);
        $member->setRole($role);
        $member->setInvitedBy($invitedBy);
        $member->setJoinedAt(new \DateTimeImmutable());
        $member->setIsActive(true);

        $this->entityManager->persist($member);
        $this->entityManager->flush();

        return $member;
    }

    /**
     * Reactivate former member
     */
    private function reactivateMember(
        OrganizationMember $member,
        string $role,
        User $invitedBy
    ): OrganizationMember {
        $member->setIsActive(true);
        $member->setLeftAt(null);
        $member->setRole($role);
        $member->setJoinedAt(new \DateTimeImmutable());
        $member->setInvitedBy($invitedBy);

        $this->entityManager->persist($member);
        $this->entityManager->flush();

        return $member;
    }

    /**
     * Remove member from organization
     */
    public function removeMemberFromOrganization(OrganizationMember $member): void
    {
        $user = $member->getUser();
        $organization = $member->getOrganization();

        // Mark member as left organization
        $this->organizationMemberRepository->markAsLeft($member);

        // Remove user from all teams in this organization
        $this->removeUserFromOrganizationTeams($user, $organization);

        // Set user's global role to MEMBER
        $this->setUserRoleToMember($user);
    }

    /**
     * Check if user can be added to organization
     */
    public function canAddUserToOrganization(User $user, Organization $organization): array
    {
        // Check if user is an admin and already has an organization
        if ($user->hasRole('ROLE_ADMIN')) {
            $userOrganizations = $this->organizationMemberRepository->findByUser($user);
            if (count($userOrganizations) > 0) {
                return [
                    'allowed' => false,
                    'reason' => 'Admin users can only belong to one organization'
                ];
            }
        }

        // Check if user is already an active member
        $existingMember = $this->organizationMemberRepository->findOneBy([
            'user' => $user,
            'organization' => $organization
        ]);

        if ($existingMember && $existingMember->isActive() && $existingMember->getLeftAt() === null) {
            return [
                'allowed' => false,
                'reason' => 'User is already a member of this organization'
            ];
        }

        return ['allowed' => true];
    }

    /**
     * Remove user from all teams in the organization
     */
    private function removeUserFromOrganizationTeams(User $user, Organization $organization): void
    {
        $teamRepository = $this->entityManager->getRepository(Team::class);
        $teamMemberRepository = $this->entityManager->getRepository(TeamMember::class);

        // Get all teams in this organization
        $teams = $teamRepository->createQueryBuilder('t')
            ->where('t.organization = :organization')
            ->setParameter('organization', $organization)
            ->getQuery()
            ->getResult();

        // Remove user from each team
        foreach ($teams as $team) {
            $teamMember = $teamMemberRepository->findOneBy([
                'team' => $team,
                'user' => $user,
                'isActive' => true
            ]);

            if ($teamMember) {
                $teamMember->setIsActive(false);
                $teamMember->setLeftAt(new \DateTimeImmutable());
                $this->entityManager->persist($teamMember);
            }
        }

        $this->entityManager->flush();
    }

    /**
     * Set user's global role to MEMBER
     */
    private function setUserRoleToMember(User $user): void
    {
        $roleRepository = $this->entityManager->getRepository(Role::class);
        $userRoleRepository = $this->entityManager->getRepository(UserRole::class);

        // Find MEMBER role
        $memberRole = $roleRepository->findOneBy(['code' => 'ROLE_MEMBER']);
        if (!$memberRole) {
            return; // MEMBER role doesn't exist, skip
        }

        // Deactivate all current roles
        $currentRoles = $userRoleRepository->findBy(['user' => $user, 'isActive' => true]);
        foreach ($currentRoles as $currentRole) {
            $currentRole->setIsActive(false);
            $this->entityManager->persist($currentRole);
        }

        // Check if user already has MEMBER role (any status)
        $existingMemberRole = $userRoleRepository->findOneBy([
            'user' => $user,
            'role' => $memberRole
        ]);

        if ($existingMemberRole) {
            // Reactivate existing MEMBER role
            $existingMemberRole->setIsActive(true);
            $this->entityManager->persist($existingMemberRole);
        } else {
            // Create new MEMBER role
            $newMemberRole = new UserRole();
            $newMemberRole->setUser($user);
            $newMemberRole->setRole($memberRole);
            $newMemberRole->setIsActive(true);
            $newMemberRole->setAssignedAt(new \DateTimeImmutable());
            $this->entityManager->persist($newMemberRole);
        }

        $this->entityManager->flush();
    }

    /**
     * Get active members for organization
     */
    public function getActiveMembers(Organization $organization): array
    {
        return $this->organizationMemberRepository->findActiveByOrganization($organization);
    }

    /**
     * Get all members (active and inactive) for organization
     */
    public function getAllMembers(Organization $organization): array
    {
        return $this->organizationMemberRepository->findAllByOrganization($organization);
    }

    /**
     * Get organization statistics
     */
    public function getOrganizationStatistics(Organization $organization): array
    {
        return $this->organizationMemberRepository->getOrganizationStatistics($organization);
    }

    /**
     * Check if user is member of organization
     */
    public function isUserMemberOfOrganization(User $user, Organization $organization): bool
    {
        return $this->organizationMemberRepository->isUserMemberOfOrganization($user, $organization);
    }
}

