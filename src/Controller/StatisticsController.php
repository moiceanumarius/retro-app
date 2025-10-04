<?php

namespace App\Controller;

use App\Repository\RetrospectiveStatisticsRepository;
use App\Repository\ActionStatisticsRepository;
use App\Repository\RetrospectiveRepository;
use App\Repository\RetrospectiveActionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * StatisticsController
 * 
 * Controller pentru statistici și analytics
 * Oferă API endpoints pentru dashboard și rapoarte
 */
#[Route('/statistics')]
class StatisticsController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RetrospectiveStatisticsRepository $retrospectiveStatsRepository,
        private ActionStatisticsRepository $actionStatsRepository,
        private RetrospectiveRepository $retrospectiveRepository,
        private RetrospectiveActionRepository $actionRepository
    ) {
    }

    /**
     * Verifică autentificarea pentru toate metodele
     */
    private function ensureAuthenticated(): void
    {
        if (!$this->getUser()) {
            throw $this->createAccessDeniedException('Authentication required');
        }
    }

    /**
     * Endpoint pentru trend-ul de completare retrospective
     * 
     * @return JsonResponse
     */
    #[Route('/completion-trend', name: 'app_statistics_completion_trend', methods: ['GET'])]
    public function getCompletionTrend(): JsonResponse
    {
        $this->ensureAuthenticated();
        $user = $this->getUser();
        $teamIds = $this->getUserTeamIds($user);

        $trendData = $this->retrospectiveStatsRepository->getCompletionTrendData($teamIds);

        return $this->json([
            'success' => true,
            'data' => $trendData
        ]);
    }

    /**
     * Endpoint pentru activitatea lunară
     * 
     * @return JsonResponse
     */
    #[Route('/monthly-activity', name: 'app_statistics_monthly_activity', methods: ['GET'])]
    public function getMonthlyActivity(): JsonResponse
    {
        $this->ensureAuthenticated();
        $user = $this->getUser();
        $teamIds = $this->getUserTeamIds($user);

        $activityData = $this->actionStatsRepository->getMonthlyActivityData($teamIds);

        return $this->json([
            'success' => true,
            'data' => $activityData
        ]);
    }

    /**
     * Endpoint principal pentru statistici dashboard
     * 
     * @return JsonResponse
     */
    #[Route('', name: 'app_statistics', methods: ['GET'])]
    public function getStatistics(): JsonResponse
    {
        $this->ensureAuthenticated();
        $user = $this->getUser();
        $teamIds = $this->getUserTeamIds($user);

        $retrospectiveStats = $this->retrospectiveStatsRepository->getBasicStatistics($teamIds);
        $actionStats = $this->actionStatsRepository->getActionStatistics($teamIds);
        $participationStats = $this->retrospectiveStatsRepository->getParticipationStatistics($teamIds);
        $productivityStats = $this->actionStatsRepository->getProductivityStatistics($teamIds);

        return $this->json([
            'success' => true,
            'data' => [
                'retrospectives' => $retrospectiveStats,
                'actions' => $actionStats,
                'participation' => $participationStats,
                'productivity' => $productivityStats,
                'last_updated' => (new \DateTime())->format('Y-m-d H:i:s')
            ]
        ]);
    }

    /**
     * Endpoint pentru statistici retrospective detaliate
     * 
     * @return JsonResponse
     */
    #[Route('/retrospectives', name: 'app_statistics_retrospectives', methods: ['GET'])]
    public function getRetrospectiveStatistics(): JsonResponse
    {
        $this->ensureAuthenticated();
        $user = $this->getUser();
        $teamIds = $this->getUserTeamIds($user);

        $basicStats = $this->retrospectiveStatsRepository->getBasicStatistics($teamIds);
        $participationStats = $this->retrospectiveStatsRepository->getParticipationStatistics($teamIds);
        $recentRetrospectives = $this->retrospectiveStatsRepository->getRecentRetrospectivesWithStats($teamIds, 10);

        return $this->json([
            'success' => true,
            'data' => [
                'basic' => $basicStats,
                'participation' => $participationStats,
                'recent' => $recentRetrospectives
            ]
        ]);
    }

    /**
     * Endpoint pentru statistici acțiuni detaliate
     * 
     * @return JsonResponse
     */
    #[Route('/actions', name: 'app_statistics_actions', methods: ['GET'])]
    public function getActionStatistics(): JsonResponse
    {
        $this->ensureAuthenticated();
        $user = $this->getUser();
        $teamIds = $this->getUserTeamIds($user);

        $actionStats = $this->actionStatsRepository->getActionStatistics($teamIds);
        $productivityStats = $this->actionStatsRepository->getProductivityStatistics($teamIds);
        $topPerformers = $this->actionStatsRepository->getTopPerformers($teamIds, 10);

        return $this->json([
            'success' => true,
            'data' => [
                'basic' => $actionStats,
                'productivity' => $productivityStats,
                'top_performers' => $topPerformers
            ]
        ]);
    }

    /**
     * Endpoint pentru dashboard widget (statistici rapide)
     * 
     * @return JsonResponse
     */
    #[Route('/dashboard', name: 'app_statistics_dashboard', methods: ['GET'])]
    public function getDashboardStatistics(): JsonResponse
    {
        $this->ensureAuthenticated();
        $user = $this->getUser();
        $teamIds = $this->getUserTeamIds($user);

        $retrospectiveStats = $this->retrospectiveStatsRepository->getBasicStatistics($teamIds);
        $actionStats = $this->actionStatsRepository->getActionStatistics($teamIds);

        // Statistici rapide pentru widget-ul dashboard
        $dashboardStats = [
            'total_retrospectives' => $retrospectiveStats['total_retrospectives'],
            'completion_rate' => $retrospectiveStats['completion_rate'],
            'total_actions' => $actionStats['total_actions'],
            'action_completion_rate' => $actionStats['completion_rate'],
            'overdue_actions' => $actionStats['overdue_actions'],
            'active_retrospectives' => $retrospectiveStats['active_retrospectives'],
            'pending_actions' => $actionStats['pending_actions']
        ];

        return $this->json([
            'success' => true,
            'data' => $dashboardStats
        ]);
    }

    /**
     * Endpoint pentru statistici per echipă
     * 
     * @return JsonResponse
     */
    #[Route('/teams', name: 'app_statistics_teams', methods: ['GET'])]
    public function getTeamStatistics(): JsonResponse
    {
        $this->ensureAuthenticated();
        $user = $this->getUser();
        $teamIds = $this->getUserTeamIds($user);

        $teamStats = [];

        foreach ($teamIds as $teamId) {
            $teamRetroStats = $this->retrospectiveStatsRepository->getBasicStatistics([$teamId]);
            $teamActionStats = $this->actionStatsRepository->getActionStatistics([$teamId]);
            
            // Get team name
            $team = $this->entityManager->getRepository(\App\Entity\Team::class)->find($teamId);
            $teamName = $team ? $team->getName() : "Team #{$teamId}";

            $teamStats[] = [
                'team_id' => $teamId,
                'team_name' => $teamName,
                'retrospectives' => $teamRetroStats,
                'actions' => $teamActionStats
            ];
        }

        return $this->json([
            'success' => true,
            'data' => $teamStats
        ]);
    }

    /**
     * Pagina de statistici (pentru Faza 2)
     * 
     * @return Response
     */
    #[Route('/analytics', name: 'app_statistics_analytics', methods: ['GET'])]
    public function analyticsDashboard(): Response
    {
        $this->ensureAuthenticated();
        $user = $this->getUser();
        
        // Verificare permisiuni - doar supervisor/admin pot vedea analytics
        if (!$user->hasAnyRole(['ROLE_ADMIN', 'ROLE_SUPERVISOR'])) {
            throw $this->createAccessDeniedException('Only supervisors and administrators can view analytics');
        }

        return $this->render('statistics/analytics.html.twig', [
            'page_title' => 'Analytics Dashboard',
            'user' => $user
        ]);
    }

    /**
     * Obține ID-urile echipelor utilizatorului curent
     * 
     * @param mixed $user
     * @return array
     */
    private function getUserTeamIds($user): array
    {
        if ($user->hasRole('ROLE_ADMIN')) {
            // Admin poate vedea toate statisticile
            $teams = $this->entityManager->getRepository(\App\Entity\Team::class)
                ->findBy(['isActive' => true]);
            return array_map(fn($team) => $team->getId(), $teams);
        }

        $teamIds = [];
        
        // Echipe pe care le deține utilizatorul
        $ownedTeams = $this->entityManager->getRepository(\App\Entity\Team::class)
            ->findBy(['owner' => $user, 'isActive' => true]);
        foreach ($ownedTeams as $team) {
            $teamIds[] = $team->getId();
        }

        // Echipe din care face parte utilizatorul (ca membru)
        $memberTeams = $this->entityManager->getRepository(\App\Entity\TeamMember::class)
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

        foreach ($memberTeams as $memberTeam) {
            $teamIds[] = $memberTeam->getTeam()->getId();
        }

        return array_unique($teamIds);
    }
}
