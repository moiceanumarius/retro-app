<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    #[Route('/', name: 'app_home')]
    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(): Response
    {
        $user = $this->getUser();
        $userRoles = $user->getActiveRoles();
        
        // Get user's teams
        $userTeams = $this->getUserTeams($user);
        
        // Check if user has organization and teams
        $hasOrganization = $this->userHasOrganization($user);
        $hasTeams = !empty($userTeams);
        $showStatistics = $hasOrganization && $hasTeams;
        
        // Calculate statistics only if user has organization and teams
        $stats = $showStatistics ? $this->calculateDashboardStats($user, $userTeams) : [];
        
        // Get recent activity only if user has organization and teams
        $recentActivity = $showStatistics ? $this->getRecentActivity($user) : [];
        
        // Get upcoming deadlines only if user has organization and teams
        $upcomingDeadlines = $showStatistics ? $this->getUpcomingDeadlines($user) : [];
        
        return $this->render('dashboard/index.html.twig', [
            'user_roles' => $userRoles,
            'is_admin' => $user->hasRole('ROLE_ADMIN'),
            'is_facilitator' => $user->hasRole('ROLE_FACILITATOR'),
            'is_supervisor' => $user->hasRole('ROLE_SUPERVISOR'),
            'is_member' => $user->hasRole('ROLE_MEMBER'),
            'user_teams' => $userTeams,
            'stats' => $stats,
            'recent_activity' => $recentActivity,
            'upcoming_deadlines' => $upcomingDeadlines,
            'show_statistics' => $showStatistics,
            'has_organization' => $hasOrganization,
            'has_teams' => $hasTeams,
        ]);
    }

    private function getUserTeams($user): array
    {
        // Get user's organization (either as member or as owner)
        $userOrganization = null;
        
        // First check if user is a member of any organization
        foreach ($user->getOrganizationMemberships() as $membership) {
            if ($membership->isActive() && $membership->getLeftAt() === null) {
                $userOrganization = $membership->getOrganization();
                break;
            }
        }
        
        // If not a member, check if user owns any organization
        if (!$userOrganization) {
            $ownedOrganizations = $user->getOwnedOrganizations();
            if (!$ownedOrganizations->isEmpty()) {
                $userOrganization = $ownedOrganizations->first();
            }
        }

        // If user has no organization, return empty array
        if (!$userOrganization) {
            return [];
        }

        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('t')
           ->from(\App\Entity\Team::class, 't')
           ->leftJoin('t.teamMembers', 'tm')
           ->leftJoin('tm.user', 'u')
           ->where('(t.owner = :user OR u = :user)')
           ->andWhere('t.organization = :organization')
           ->andWhere('t.isActive = :active')
           ->setParameter('user', $user)
           ->setParameter('organization', $userOrganization)
           ->setParameter('active', true)
           ->orderBy('t.name', 'ASC');

        return $qb->getQuery()->getResult();
    }

    private function userHasOrganization($user): bool
    {
        // Check if user is a member of any organization
        $organizationMember = $this->entityManager->getRepository(\App\Entity\OrganizationMember::class)
            ->findOneBy(['user' => $user, 'isActive' => true]);
            
        if ($organizationMember !== null) {
            return true;
        }
        
        // Check if user owns any organization
        $ownedOrganizations = $user->getOwnedOrganizations();
        return !$ownedOrganizations->isEmpty();
    }

    private function calculateDashboardStats($user, $userTeams): array
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
        $qbRetro = $this->entityManager->createQueryBuilder();
        $qbRetro->select('r.status, COUNT(r) as count')
                ->from(\App\Entity\Retrospective::class, 'r')
                ->where('r.team IN (:teams)')
                ->setParameter('teams', $teamIds)
                ->groupBy('r.status');

        $retroStats = [];
        foreach ($qbRetro->getQuery()->getResult() as $stat) {
            $retroStats[$stat['status']] = $stat['count'];
        }

        // Action statistics
        $qbActions = $this->entityManager->createQueryBuilder();
        $qbActions->select('a.status, COUNT(a) as count')
                  ->from(\App\Entity\RetrospectiveAction::class, 'a')
                  ->leftJoin('a.retrospective', 'r')
                  ->where('r.team IN (:teams)')
                  ->setParameter('teams', $teamIds)
                  ->groupBy('a.status');

        $actionStats = [];
        foreach ($qbActions->getQuery()->getResult() as $stat) {
            $actionStats[$stat['status']] = $stat['count'];
        }

        // Overdue actions
        $qbOverdue = $this->entityManager->createQueryBuilder();
        $qbOverdue->select('COUNT(a) as count')
                  ->from(\App\Entity\RetrospectiveAction::class, 'a')
                  ->leftJoin('a.retrospective', 'r')
                  ->where('r.team IN (:teams)')
                  ->andWhere('a.dueDate < :currentDate')
                  ->andWhere('a.status NOT IN (:completedStatuses)')
                  ->setParameter('teams', $teamIds)
                  ->setParameter('currentDate', new \DateTime())
                  ->setParameter('completedStatuses', ['completed', 'cancelled']);

        $overdueCount = $qbOverdue->getQuery()->getSingleScalarResult();

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

    private function getRecentActivity($user): array
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

    private function getUpcomingDeadlines($user): array
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
