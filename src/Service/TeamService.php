<?php

namespace App\Service;

use App\Entity\Team;
use App\Entity\TeamMember;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * TeamService
 * 
 * Service for managing teams
 * Handles team CRUD operations and access control
 */
class TeamService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OrganizationService $organizationService
    ) {}

    /**
     * Get teams owned by user
     */
    public function getOwnedTeams(User $user): array
    {
        return $this->entityManager->getRepository(Team::class)
            ->findBy(['owner' => $user, 'isActive' => true]);
    }

    /**
     * Get teams where user is a member (not owner)
     */
    public function getMemberTeams(User $user): array
    {
        $result = $this->entityManager->getRepository(TeamMember::class)
            ->createQueryBuilder('tm')
            ->join('tm.team', 't')
            ->where('tm.user = :user')
            ->andWhere('tm.isActive = :active')
            ->andWhere('t.isActive = :active')
            ->andWhere('t.owner != :user')
            ->setParameter('user', $user)
            ->setParameter('active', true)
            ->getQuery()
            ->getResult();

        return array_map(fn($tm) => $tm->getTeam(), $result);
    }

    /**
     * Create new team
     */
    public function createTeam(Team $team, User $owner): void
    {
        // Set organization for team
        $userOrganization = $this->organizationService->getUserOrganization($owner);
        
        if ($userOrganization) {
            $team->setOrganization($userOrganization);
        }

        // Add owner as team member
        $ownerMember = new TeamMember();
        $ownerMember->setTeam($team);
        $ownerMember->setUser($owner);
        $ownerMember->setRole('Owner');
        $ownerMember->setInvitedBy($owner);

        $this->entityManager->persist($team);
        $this->entityManager->persist($ownerMember);
        $this->entityManager->flush();
    }

    /**
     * Update team
     */
    public function updateTeam(Team $team): void
    {
        $this->entityManager->persist($team);
        $this->entityManager->flush();
    }

    /**
     * Delete team (soft delete)
     */
    public function deleteTeam(Team $team): void
    {
        $team->setIsActive(false);
        $this->entityManager->persist($team);
        $this->entityManager->flush();
    }

    /**
     * Check if user has access to team
     */
    public function hasTeamAccess(Team $team, User $user): bool
    {
        // Owner has access
        if ($team->getOwner()->getId() === $user->getId()) {
            return true;
        }

        // Active members have access
        return $team->hasMember($user);
    }

    /**
     * Check if user can manage team (owner only)
     */
    public function canManageTeam(Team $team, User $user): bool
    {
        return $team->getOwner()->getId() === $user->getId();
    }

    /**
     * Check if user can create teams
     */
    public function canCreateTeams(User $user): bool
    {
        return $user->hasAnyRole(['ROLE_ADMIN', 'ROLE_SUPERVISOR', 'ROLE_FACILITATOR']);
    }

    /**
     * Get team by ID with access check
     */
    public function getTeamWithAccessCheck(int $teamId, User $user): ?Team
    {
        $team = $this->entityManager->getRepository(Team::class)->find($teamId);

        if (!$team || !$team->isActive()) {
            return null;
        }

        if (!$this->hasTeamAccess($team, $user)) {
            return null;
        }

        return $team;
    }

    /**
     * Get users available for team (from same organization or without organization)
     */
    public function getAvailableUsersForTeam(Team $team): array
    {
        $userRepository = $this->entityManager->getRepository(User::class);
        $qb = $userRepository->createQueryBuilder('u')
            ->leftJoin('u.organizationMemberships', 'om')
            ->leftJoin('om.organization', 'o')
            ->orderBy('u.firstName', 'ASC');

        if ($team->getOrganization()) {
            // Show users from the same organization as the team AND users without any organization
            $qb->where('o.id = :teamOrgId OR o.id IS NULL')
               ->setParameter('teamOrgId', $team->getOrganization()->getId());
        } else {
            // If team has no organization, show only users without organization
            $qb->where('o.id IS NULL');
        }

        return $qb->getQuery()->getResult();
    }
}

