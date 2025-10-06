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
        
        // Get user's active organizations
        $activeOrganizations = $this->getUserActiveOrganizations($user);

        // Get the selected team from the session or use the first team
        $session = $this->container->get('request_stack')->getSession();
        $selectedTeamId = $session->get('selected_team_id');
        
        $selectedTeam = null;
        $teamStats = null;
        $recentRetrospectives = [];
        $upcomingRetrospectives = [];
        $upcomingActions = [];

        if (!empty($userTeams)) {
            // If a team is selected in the session, try to find it
            if ($selectedTeamId) {
                foreach ($userTeams as $team) {
                    if ($team->getId() == $selectedTeamId) {
                        $selectedTeam = $team;
                        break;
                    }
                }
            }
            
            // If no team is selected or team not found, use the first team
            if (!$selectedTeam) {
                $selectedTeam = $userTeams[0];
                $session->set('selected_team_id', $selectedTeam->getId());
            }

            // Get team statistics
            $teamStats = $this->getTeamStats($selectedTeam);
            
            // Get recent retrospectives for selected team
            $recentRetrospectives = $this->getRecentRetrospectives($selectedTeam, 5);
            
            // Get upcoming retrospectives for selected team
            $upcomingRetrospectives = $this->getUpcomingRetrospectives($selectedTeam, 5);
            
            // Get upcoming actions for the user
            $upcomingActions = $this->getUpcomingActions($user);
        }

        return $this->render('dashboard/index.html.twig', [
            'user' => $user,
            'userRoles' => $userRoles,
            'userTeams' => $userTeams,
            'hasOrganization' => $hasOrganization,
            'hasTeams' => $hasTeams,
            'showStatistics' => $showStatistics,
            'activeOrganizations' => $activeOrganizations,
            'selectedTeam' => $selectedTeam,
            'teamStats' => $teamStats,
            'recentRetrospectives' => $recentRetrospectives,
            'upcomingRetrospectives' => $upcomingRetrospectives,
            'upcomingActions' => $upcomingActions,
        ]);
    }

    private function getUserTeams($user): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from('App\Entity\Team', 't')
            ->join('t.teamMembers', 'tm')
            ->where('tm.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();
    }

    private function userHasOrganization($user): bool
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('COUNT(om.id)')
            ->from('App\Entity\OrganizationMember', 'om')
            ->where('om.user = :user')
            ->setParameter('user', $user);

        return $qb->getQuery()->getSingleScalarResult() > 0;
    }

    private function getUserActiveOrganizations($user): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from('App\Entity\Organization', 'o')
            ->join('o.members', 'om')
            ->where('om.user = :user')
            ->andWhere('om.isActive = 1')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();
    }

    private function getTeamStats($team): array
    {
        $em = $this->entityManager;
        
        // Total retrospectives for the team
        $totalRetrospectives = $em->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from('App\Entity\Retrospective', 'r')
            ->where('r.team = :team')
            ->setParameter('team', $team)
            ->getQuery()
            ->getSingleScalarResult();

        // Completed retrospectives
        $completedRetrospectives = $em->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from('App\Entity\Retrospective', 'r')
            ->where('r.team = :team')
            ->andWhere('r.status = :status')
            ->setParameter('team', $team)
            ->setParameter('status', 'completed')
            ->getQuery()
            ->getSingleScalarResult();

        // Total actions
        $totalActions = $em->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from('App\Entity\RetrospectiveAction', 'a')
            ->join('a.retrospective', 'r')
            ->where('r.team = :team')
            ->setParameter('team', $team)
            ->getQuery()
            ->getSingleScalarResult();

        // Completed actions
        $completedActions = $em->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from('App\Entity\RetrospectiveAction', 'a')
            ->join('a.retrospective', 'r')
            ->where('r.team = :team')
            ->andWhere('a.status = :status')
            ->setParameter('team', $team)
            ->setParameter('status', 'completed')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'total_retrospectives' => $totalRetrospectives,
            'completed_retrospectives' => $completedRetrospectives,
            'total_actions' => $totalActions,
            'completed_actions' => $completedActions,
        ];
    }

    private function getRecentRetrospectives($team, $limit = 5): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from('App\Entity\Retrospective', 'r')
            ->where('r.team = :team')
            ->setParameter('team', $team)
            ->orderBy('r.date', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    private function getUpcomingRetrospectives($team, $limit = 5): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from('App\Entity\Retrospective', 'r')
            ->where('r.team = :team')
            ->andWhere('r.date >= :now')
            ->setParameter('team', $team)
            ->setParameter('now', new \DateTime())
            ->orderBy('r.date', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    private function getUpcomingActions($user): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('a')
           ->from('App\Entity\RetrospectiveAction', 'a')
           ->join('a.retrospective', 'r')
           ->join('r.team', 't')
           ->join('t.teamMembers', 'tm')
           ->where('tm.user = :user')
           ->andWhere('a.status NOT IN (:completedStatuses)')
           ->andWhere('a.dueDate >= :currentDate')
           ->setParameter('user', $user)
           ->setParameter('currentDate', new \DateTime())
           ->setParameter('completedStatuses', ['completed', 'cancelled'])
           ->orderBy('a.dueDate', 'ASC')
           ->setMaxResults(10);

        return $qb->getQuery()->getResult();
    }
}
