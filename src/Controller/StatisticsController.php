<?php

namespace App\Controller;

use App\Service\StatisticsService;
use App\Service\TeamAccessService;
use App\Service\PeriodService;
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
 * Refactored to use services for better testability
 */
#[Route('/statistics')]
class StatisticsController extends AbstractController
{
    public function __construct(
        private StatisticsService $statisticsService,
        private TeamAccessService $teamAccessService,
        private PeriodService $periodService
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
        $teamIds = $this->teamAccessService->getFilteredTeamIds($user, $request);

        $trendData = $this->statisticsService->getCompletionTrendData($teamIds);

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
        $teamIds = $this->teamAccessService->getFilteredTeamIds($user, $request);

        $activityData = $this->statisticsService->getMonthlyActivityData($teamIds);

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
        $teamIds = $this->teamAccessService->getUserTeamIds($user);

        $retrospectiveStats = $this->statisticsService->getBasicRetrospectiveStatistics($teamIds);
        $actionStats = $this->statisticsService->getActionStatistics($teamIds);
        $participationStats = $this->statisticsService->getParticipationStatistics($teamIds);
        $productivityStats = $this->statisticsService->getProductivityStatistics($teamIds);

        return $this->json([
            'success' => true,
            'data' => [
                'retrospectives' => $retrospectiveStats,
                'actions' => $actionStats,
                'participation' => $participationStats,
                'productivity' => $productivityStats
            ]
        ]);
    }

    /**
     * Endpoint pentru statistici retrospective
     * 
     * @return JsonResponse
     */
    #[Route('/retrospectives', name: 'app_statistics_retrospectives', methods: ['GET'])]
    public function getRetrospectiveStatistics(Request $request): JsonResponse
    {
        $this->ensureAuthenticated();
        $user = $this->getUser();
        $teamIds = $this->teamAccessService->getFilteredTeamIds($user, $request);

        $retrospectiveStats = $this->statisticsService->getBasicRetrospectiveStatistics($teamIds);

        return $this->json([
            'success' => true,
            'data' => $retrospectiveStats
        ]);
    }

    /**
     * Endpoint pentru statistici acțiuni
     * 
     * @return JsonResponse
     */
    #[Route('/actions', name: 'app_statistics_actions', methods: ['GET'])]
    public function getActionStatistics(Request $request): JsonResponse
    {
        $this->ensureAuthenticated();
        $user = $this->getUser();
        $teamIds = $this->teamAccessService->getFilteredTeamIds($user, $request);

        $actionStats = $this->statisticsService->getActionStatistics($teamIds);

        return $this->json([
            'success' => true,
            'data' => $actionStats
        ]);
    }

    /**
     * Endpoint pentru statistici echipe
     * 
     * @return JsonResponse
     */
    #[Route('/teams', name: 'app_statistics_teams', methods: ['GET'])]
    public function getTeamStatistics(Request $request): JsonResponse
    {
        $this->ensureAuthenticated();
        $user = $this->getUser();
        
        return $this->json($this->teamAccessService->getTeamStatisticsForUser($user, $request));
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
        $teamIds = $this->teamAccessService->getFilteredTeamIds($user, $request);

        $categoryData = $this->statisticsService->getCategoryDistributionData($teamIds);

        return $this->json([
            'success' => true,
            'data' => $categoryData
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
        $teamIds = $this->teamAccessService->getFilteredTeamIds($user, $request);
        
        // Get period parameter (default: 6 months)
        $period = $request->query->get('period', $this->periodService->getDefaultPeriod());
        $startDate = $this->periodService->getStartDateForPeriod($period);

        $trendData = $this->statisticsService->getCategoryTrendData($teamIds, $startDate);

        return $this->json([
            'success' => true,
            'data' => $trendData
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
}