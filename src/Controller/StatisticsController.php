<?php

namespace App\Controller;

use App\Repository\RetrospectiveStatisticsRepository;
use App\Repository\ActionStatisticsRepository;
use App\Repository\RetrospectiveRepository;
use App\Repository\RetrospectiveActionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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
    public function getCompletionTrend(Request $request): JsonResponse
    {
        $this->ensureAuthenticated();
        $user = $this->getUser();
        $teamIds = $this->getFilteredTeamIds($user, $request);

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
    public function getMonthlyActivity(Request $request): JsonResponse
    {
        $this->ensureAuthenticated();
        $user = $this->getUser();
        $teamIds = $this->getFilteredTeamIds($user, $request);

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
    public function getRetrospectiveStatistics(Request $request): JsonResponse
    {
        $this->ensureAuthenticated();
        $user = $this->getUser();
        $teamIds = $this->getFilteredTeamIds($user, $request);

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
    public function getActionStatistics(Request $request): JsonResponse
    {
        $this->ensureAuthenticated();
        $user = $this->getUser();
        $teamIds = $this->getFilteredTeamIds($user, $request);

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
    public function getDashboardStatistics(Request $request): JsonResponse
    {
        $this->ensureAuthenticated();
        $user = $this->getUser();
        
        // Get team ID from query parameter (optional)
        $teamId = $request->query->get('teamId');
        
        // Get user's team IDs
        $userTeamIds = $this->getUserTeamIds($user);
        
        // Filter by specific team if provided and user has access to it
        if ($teamId && in_array((int)$teamId, $userTeamIds)) {
            $teamIds = [(int)$teamId];
        } else {
            $teamIds = $userTeamIds;
        }

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
    public function getTeamStatistics(Request $request): JsonResponse
    {
        $this->ensureAuthenticated();
        $user = $this->getUser();
        $teamIds = $this->getFilteredTeamIds($user, $request);

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
     * Endpoint pentru evoluția categoriilor în timp
     * 
     * @return JsonResponse
     */
    #[Route('/category-trend', name: 'app_statistics_category_trend', methods: ['GET'])]
    public function getCategoryTrend(Request $request): JsonResponse
    {
        $this->ensureAuthenticated();
        $user = $this->getUser();
        $teamIds = $this->getFilteredTeamIds($user, $request);
        
        // Get period parameter (default: 6 months)
        $period = $request->query->get('period', '6months');
        $startDate = $this->getStartDateForPeriod($period);

        // Get category trend over time using raw SQL
        $sql = "
            SELECT ri.category, 
                   DATE_FORMAT(r.created_at, '%Y-%m') as month, 
                   COUNT(ri.id) as count
            FROM retrospective_item ri 
            JOIN retrospective r ON ri.retrospective_id = r.id 
            WHERE r.team_id IN (:teams) 
            AND r.created_at >= :startDate
            GROUP BY ri.category, month 
            ORDER BY month ASC
        ";
        
        // Convert team IDs to comma-separated string for IN clause
        $teamIdsString = implode(',', array_map('intval', $teamIds));
        
        $sql = "
            SELECT ri.category, 
                   DATE_FORMAT(r.created_at, '%Y-%m') as month, 
                   COUNT(ri.id) as count
            FROM retrospective_item ri 
            JOIN retrospective r ON ri.retrospective_id = r.id 
            WHERE r.team_id IN ($teamIdsString) 
            AND r.created_at >= :startDate
            GROUP BY ri.category, month 
            ORDER BY month ASC
        ";
        
        $stmt = $this->entityManager->getConnection()->prepare($sql);
        $stmt->bindValue('startDate', $startDate->format('Y-m-d H:i:s'));
        $result = $stmt->executeQuery();
        $results = $result->fetchAllAssociative();

        // Process results from raw SQL
        $categoryLabels = [
            'wrong' => 'What went wrong',
            'good' => 'What went good', 
            'improved' => 'What can be improved',
            'random' => 'Random feedback'
        ];
        
        $trendData = [];
        $months = [];
        
        // Initialize all categories
        foreach ($categoryLabels as $key => $label) {
            $trendData[$label] = [];
        }
        
        // Process results
        foreach ($results as $result) {
            $category = $result['category'];
            $month = $result['month'];
            $count = $result['count'];
            
            $label = $categoryLabels[$category] ?? ucfirst($category);
            if (!isset($trendData[$label])) {
                $trendData[$label] = [];
            }
            $trendData[$label][$month] = $count;
            
            if (!in_array($month, $months)) {
                $months[] = $month;
            }
        }
        
        // Fill missing months with 0
        sort($months);
        foreach ($trendData as $category => $data) {
            foreach ($months as $month) {
                if (!isset($data[$month])) {
                    $trendData[$category][$month] = 0;
                }
            }
            // Sort by month
            ksort($trendData[$category]);
        }

        return $this->json([
            'success' => true,
            'data' => [
                'months' => $months,
                'categories' => $trendData
            ]
        ]);
    }
    
    /**
     * Get start date based on period parameter
     */
    private function getStartDateForPeriod(string $period): \DateTime
    {
        switch ($period) {
            case '1month':
                return new \DateTime('-1 month');
            case '3months':
                return new \DateTime('-3 months');
            case '6months':
                return new \DateTime('-6 months');
            case '1year':
                return new \DateTime('-1 year');
            default:
                return new \DateTime('-6 months'); // Default to 6 months
        }
    }

    /**
     * Endpoint pentru distribuția categoriilor de retrospective items
     * 
     * @return JsonResponse
     */
    #[Route('/category-distribution', name: 'app_statistics_category_distribution', methods: ['GET'])]
    public function getCategoryDistribution(Request $request): JsonResponse
    {
        $this->ensureAuthenticated();
        $user = $this->getUser();
        $teamIds = $this->getFilteredTeamIds($user, $request);

        // Get category distribution for retrospective items
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('ri.category, COUNT(ri) as count')
           ->from(\App\Entity\RetrospectiveItem::class, 'ri')
           ->join('ri.retrospective', 'r')
           ->where('r.team IN (:teams)')
           ->setParameter('teams', $teamIds)
           ->groupBy('ri.category');

        $categoryData = [];
        $categoryLabels = [
            'wrong' => 'What went wrong',
            'good' => 'What went good', 
            'improved' => 'What can be improved',
            'random' => 'Random feedback'
        ];

        foreach ($qb->getQuery()->getResult() as $stat) {
            $category = $stat['category'];
            $label = $categoryLabels[$category] ?? ucfirst($category);
            $categoryData[$label] = $stat['count'];
        }

        return $this->json([
            'success' => true,
            'data' => $categoryData
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
     * Obține ID-urile echipelor filtrate pe baza request-ului
     * 
     * @param mixed $user
     * @param Request $request
     * @return array
     */
    private function getFilteredTeamIds($user, Request $request): array
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
     * Obține ID-urile echipelor utilizatorului curent (filtrate pe organizație)
     * 
     * @param mixed $user
     * @return array
     */
    private function getUserTeamIds($user): array
    {
        // Get user's organization
        $userOrganization = null;
        foreach ($user->getOrganizationMemberships() as $membership) {
            if ($membership->isActive() && $membership->getLeftAt() === null) {
                $userOrganization = $membership->getOrganization();
                break;
            }
        }

        // If user has no organization, return empty array
        if (!$userOrganization) {
            return [];
        }

        $teamIds = [];
        
        // Echipe pe care le deține utilizatorul din organizația sa
        $ownedTeams = $this->entityManager->getRepository(\App\Entity\Team::class)
            ->createQueryBuilder('t')
            ->where('t.owner = :user')
            ->andWhere('t.isActive = :active')
            ->andWhere('t.organization = :organization')
            ->setParameter('user', $user)
            ->setParameter('active', true)
            ->setParameter('organization', $userOrganization)
            ->getQuery()
            ->getResult();

        foreach ($ownedTeams as $team) {
            $teamIds[] = $team->getId();
        }

        // Echipe din care face parte utilizatorul (ca membru) din organizația sa
        $memberTeams = $this->entityManager->getRepository(\App\Entity\TeamMember::class)
            ->createQueryBuilder('tm')
            ->join('tm.team', 't')
            ->where('tm.user = :user')
            ->andWhere('tm.isActive = :active')
            ->andWhere('t.isActive = :active')
            ->andWhere('t.owner != :user')
            ->andWhere('t.organization = :organization')
            ->setParameter('user', $user)
            ->setParameter('active', true)
            ->setParameter('organization', $userOrganization)
            ->getQuery()
            ->getResult();

        foreach ($memberTeams as $memberTeam) {
            $teamIds[] = $memberTeam->getTeam()->getId();
        }

        return array_unique($teamIds);
    }
}
