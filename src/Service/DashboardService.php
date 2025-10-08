<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * DashboardService
 * 
 * Service for dashboard statistics and activity
 * Handles dashboard data aggregation and calculations
 */
class DashboardService
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    /**
     * Calculate dashboard statistics for user's teams
     */
    public function calculateDashboardStats(User $user, array $userTeams): array
    {
        $teamIds = array_map(fn($team) => $team->getId(), $userTeams);
        
        if (empty($teamIds)) {
            return [
                'total_teams' => 0,
                'total_retrospectives' => 0,
                'active_retrospectives' => 0,
                'completed_retrospectives' => 0,
                'total_actions' => 0,
                'pending_actions' => 0,
                'completed_actions' => 0,
                'overdue_actions' => 0,
            ];
        }

        // Retrospective statistics
        $retroStats = $this->getRetrospectiveStats($teamIds);
        
        // Action statistics
        $actionStats = $this->getActionStats($teamIds);
        
        // Overdue actions count
        $overdueCount = $this->getOverdueActionsCount($teamIds);

        return [
            'total_teams' => count($userTeams),
            'total_retrospectives' => array_sum($retroStats),
            'active_retrospectives' => $retroStats['active'] ?? 0,
            'completed_retrospectives' => $retroStats['completed'] ?? 0,
            'total_actions' => array_sum($actionStats),
            'pending_actions' => ($actionStats['pending'] ?? 0) + ($actionStats['open'] ?? 0),
            'completed_actions' => $actionStats['completed'] ?? 0,
            'overdue_actions' => $overdueCount,
        ];
    }

    /**
     * Get retrospective statistics by status
     */
    private function getRetrospectiveStats(array $teamIds): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('r.status, COUNT(r) as count')
           ->from(\App\Entity\Retrospective::class, 'r')
           ->where('r.team IN (:teams)')
           ->setParameter('teams', $teamIds)
           ->groupBy('r.status');

        $stats = [];
        foreach ($qb->getQuery()->getResult() as $stat) {
            $stats[$stat['status']] = $stat['count'];
        }

        return $stats;
    }

    /**
     * Get action statistics by status
     */
    private function getActionStats(array $teamIds): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('a.status, COUNT(a) as count')
           ->from(\App\Entity\RetrospectiveAction::class, 'a')
           ->leftJoin('a.retrospective', 'r')
           ->where('r.team IN (:teams)')
           ->setParameter('teams', $teamIds)
           ->groupBy('a.status');

        $stats = [];
        foreach ($qb->getQuery()->getResult() as $stat) {
            $stats[$stat['status']] = $stat['count'];
        }

        return $stats;
    }

    /**
     * Get overdue actions count
     */
    private function getOverdueActionsCount(array $teamIds): int
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('COUNT(a) as count')
           ->from(\App\Entity\RetrospectiveAction::class, 'a')
           ->leftJoin('a.retrospective', 'r')
           ->where('r.team IN (:teams)')
           ->andWhere('a.dueDate < :currentDate')
           ->andWhere('a.status NOT IN (:completedStatuses)')
           ->setParameter('teams', $teamIds)
           ->setParameter('currentDate', new \DateTime())
           ->setParameter('completedStatuses', ['completed', 'cancelled']);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Get recent activity for user
     */
    public function getRecentActivity(User $user): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('r')
           ->from(\App\Entity\Retrospective::class, 'r')
           ->leftJoin('r.team', 't')
           ->leftJoin('t.teamMembers', 'tm')
           ->leftJoin('tm.user', 'u')
           ->where('r.facilitator = :user OR t.owner = :user OR u = :user')
           ->setParameter('user', $user)
           ->orderBy('r.createdAt', 'DESC')
           ->setMaxResults(5);

        return $qb->getQuery()->getResult();
    }

    /**
     * Get upcoming deadlines for user
     */
    public function getUpcomingDeadlines(User $user): array
    {
        $nextWeek = new \DateTime('+7 days');
        
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('a')
           ->from(\App\Entity\RetrospectiveAction::class, 'a')
           ->leftJoin('a.retrospective', 'r')
           ->leftJoin('r.team', 't')
           ->leftJoin('t.teamMembers', 'tm')
           ->leftJoin('tm.user', 'u')
           ->where('a.assignedTo = :user OR t.owner = :user OR u = :user')
           ->andWhere('a.dueDate IS NOT NULL')
           ->andWhere('a.dueDate <= :nextWeek')
           ->andWhere('a.dueDate >= :currentDate')
           ->andWhere('a.status NOT IN (:completedStatuses)')
           ->setParameter('user', $user)
           ->setParameter('nextWeek', $nextWeek)
           ->setParameter('currentDate', new \DateTime())
           ->setParameter('completedStatuses', ['completed', 'cancelled'])
           ->orderBy('a.dueDate', 'ASC')
           ->setMaxResults(10);

        return $qb->getQuery()->getResult();
    }
}

