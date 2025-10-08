<?php

namespace App\Service;

use App\Entity\Team;
use App\Entity\TeamMember;
use App\Entity\User;
use App\Entity\UserRole;
use Doctrine\ORM\EntityManagerInterface;

/**
 * TeamMemberService
 * 
 * Service for managing team members
 * Handles member addition, removal, and role management
 */
class TeamMemberService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OrganizationMemberService $organizationMemberService
    ) {}

    /**
     * Add member to team
     */
    public function addMemberToTeam(
        User $user,
        Team $team,
        string $role,
        User $invitedBy
    ): TeamMember {
        // Check if user is already a member
        if ($team->hasMember($user)) {
            throw new \RuntimeException('User is already a member of this team');
        }

        $teamMember = new TeamMember();
        $teamMember->setTeam($team);
        $teamMember->setUser($user);
        $teamMember->setInvitedBy($invitedBy);

        // Handle role assignment
        $this->assignRoleToMember($teamMember, $role, $user);

        // Automatically add user to organization when added to team
        $this->ensureUserInOrganization($user, $team, $invitedBy);

        $this->entityManager->persist($teamMember);
        $this->entityManager->flush();

        return $teamMember;
    }

    /**
     * Assign role to team member
     */
    private function assignRoleToMember(TeamMember $teamMember, string $selectedRole, User $user): void
    {
        // Handle "Current Role" option - don't modify user's role
        if ($selectedRole === 'CURRENT_ROLE') {
            $userRoleRepository = $this->entityManager->getRepository(UserRole::class);
            $userRole = $userRoleRepository->findOneBy([
                'user' => $user,
                'isActive' => true
            ]);

            if ($userRole) {
                $teamMember->setRole($userRole->getRole()->getName());
            } else {
                // Fallback to Member if no role found
                $teamMember->setRole('Member');
            }
        } else {
            $teamMember->setRole($selectedRole);
        }
    }

    /**
     * Ensure user is in organization when added to team
     */
    private function ensureUserInOrganization(User $user, Team $team, User $invitedBy): void
    {
        $teamOrganization = $team->getOrganization();

        if (!$teamOrganization) {
            return;
        }

        // Check if user is already a member of the organization
        if ($this->organizationMemberService->isUserMemberOfOrganization($user, $teamOrganization)) {
            return;
        }

        // Add user to organization
        $this->organizationMemberService->addMemberToOrganization(
            $user,
            $teamOrganization,
            'Member',
            $invitedBy
        );
    }

    /**
     * Remove member from team
     */
    public function removeMemberFromTeam(TeamMember $member): void
    {
        // Cannot remove owner
        if ($member->isOwner()) {
            throw new \RuntimeException('Cannot remove team owner');
        }

        $member->setIsActive(false);
        $member->setLeftAt(new \DateTimeImmutable());
        $this->entityManager->persist($member);
        $this->entityManager->flush();
    }

    /**
     * Get active members for team
     */
    public function getActiveMembers(Team $team): array
    {
        return $team->getActiveMembers()->toArray();
    }

    /**
     * Check if user is team member
     */
    public function isTeamMember(Team $team, User $user): bool
    {
        return $team->hasMember($user);
    }

    /**
     * Get member by user
     */
    public function getMemberByUser(Team $team, User $user): ?TeamMember
    {
        return $team->getMemberByUser($user);
    }
}

