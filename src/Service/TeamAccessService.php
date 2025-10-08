<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * TeamAccessService
 * 
 * Service pentru gestionarea logicii de acces la echipe
 * Extrage logica de filtrare și verificare a echipelor din controllere
 */
class TeamAccessService
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    /**
     * Get all team IDs that a user has access to
     */
    public function getUserTeamIds(User $user): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('t.id')
           ->from(\App\Entity\Team::class, 't')
           ->leftJoin('t.teamMembers', 'tm')
           ->leftJoin('tm.user', 'u')
           ->where('(t.owner = :user OR u = :user)')
           ->andWhere('t.isActive = :active')
           ->setParameter('user', $user)
           ->setParameter('active', true);

        $results = $qb->getQuery()->getResult();
        return array_column($results, 'id');
    }

    /**
     * Get filtered team IDs based on request parameters and user access
     */
    public function getFilteredTeamIds(User $user, Request $request): array
    {
        // Get team ID from query parameter (optional)
        $teamId = $request->query->get('teamId');

        // Get user's team IDs
        $userTeamIds = $this->getUserTeamIds($user);

        // Filter by specific team if provided and user has access to it
        if ($teamId && in_array((int)$teamId, $userTeamIds)) {
            return [(int)$teamId];
        }

        return $userTeamIds;
    }

    /**
     * Check if user has access to a specific team
     */
    public function hasAccessToTeam(User $user, int $teamId): bool
    {
        $team = $this->entityManager->find(\App\Entity\Team::class, $teamId);
        
        if (!$team) {
            return false;
        }

        // Check if user is team owner
        if ($team->getOwner()->getId() === $user->getId()) {
            return true;
        }

        // Check if user is team member
        foreach ($team->getTeamMembers() as $teamMember) {
            if ($teamMember->getUser()->getId() === $user->getId() && $teamMember->isActive()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get teams that user can access (as owner or member)
     */
    public function getUserTeams(User $user): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('t')
           ->from(\App\Entity\Team::class, 't')
           ->leftJoin('t.teamMembers', 'tm')
           ->leftJoin('tm.user', 'u')
           ->where('(t.owner = :user OR u = :user)')
           ->andWhere('t.isActive = :active')
           ->setParameter('user', $user)
           ->setParameter('active', true)
           ->orderBy('t.name', 'ASC');

        return $qb->getQuery()->getResult();
    }

    /**
     * Get teams for user based on their role permissions
     */
    public function getUserTeamsByRole(User $user): array
    {
        if ($user->hasAnyRole(['ROLE_ADMIN', 'ROLE_SUPERVISOR', 'ROLE_FACILITATOR'])) {
            // For admins, supervisors and facilitators, show all teams they have access to
            return $this->getUserTeams($user);
        } else {
            // For regular users, only show teams they're members of
            $qb = $this->entityManager->createQueryBuilder();
            $qb->select('t')
               ->from(\App\Entity\Team::class, 't')
               ->leftJoin('t.teamMembers', 'tm')
               ->leftJoin('tm.user', 'u')
               ->where('u = :user')
               ->andWhere('t.isActive = :active')
               ->andWhere('tm.isActive = :active')
               ->setParameter('user', $user)
               ->setParameter('active', true)
               ->orderBy('t.name', 'ASC');

            return $qb->getQuery()->getResult();
        }
    }

    /**
     * Check if user can manage a specific team
     */
    public function canManageTeam(User $user, int $teamId): bool
    {
        // Admins and supervisors can manage any team
        if ($user->hasAnyRole(['ROLE_ADMIN', 'ROLE_SUPERVISOR'])) {
            return true;
        }

        // Team owners can manage their own teams
        $team = $this->entityManager->find(\App\Entity\Team::class, $teamId);
        if ($team && $team->getOwner()->getId() === $user->getId()) {
            return true;
        }

        return false;
    }

    /**
     * Get team statistics for user's accessible teams
     */
    public function getTeamStatisticsForUser(User $user, Request $request): array
    {
        $teamIds = $this->getFilteredTeamIds($user, $request);
        
        if (empty($teamIds)) {
            return [
                'success' => true,
                'data' => []
            ];
        }

        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('t.id as team_id, t.name as team_name, COUNT(r) as total_retrospectives, 
                    SUM(CASE WHEN r.status = \'completed\' THEN 1 ELSE 0 END) as completed_retrospectives,
                    SUM(CASE WHEN r.status = \'active\' THEN 1 ELSE 0 END) as active_retrospectives')
           ->from(\App\Entity\Team::class, 't')
           ->leftJoin('t.retrospectives', 'r')
           ->where('t.id IN (:teams)')
           ->setParameter('teams', $teamIds)
           ->groupBy('t.id, t.name')
           ->orderBy('t.name', 'ASC');

        $results = $qb->getQuery()->getResult();
        
        $teamStats = [];
        foreach ($results as $result) {
            $totalRetrospectives = (int)$result['total_retrospectives'];
            $completedRetrospectives = (int)$result['completed_retrospectives'];
            $activeRetrospectives = (int)$result['active_retrospectives'];
            
            $teamStats[] = [
                'team_id' => $result['team_id'],
                'team_name' => $result['team_name'],
                'retrospectives' => [
                    'total' => $totalRetrospectives,
                    'completed' => $completedRetrospectives,
                    'active' => $activeRetrospectives,
                    'completion_rate' => $totalRetrospectives > 0 ? round(($completedRetrospectives / $totalRetrospectives) * 100, 1) : 0
                ]
            ];
        }

        return [
            'success' => true,
            'data' => $teamStats
        ];
    }
}
