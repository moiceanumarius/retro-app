<?php

namespace App\Controller;

use App\Service\DashboardService;
use App\Service\OrganizationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    public function __construct(
        private DashboardService $dashboardService,
        private OrganizationService $organizationService
    ) {}

    #[Route('/', name: 'app_home')]
    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(): Response
    {
        $user = $this->getUser();
        $userRoles = $user->getActiveRoles();
        
        // Use OrganizationService to get user's teams
        $userTeams = $this->organizationService->getUserTeamsInOrganization($user);
        
        // Use OrganizationService to check if user has organization
        $hasOrganization = $this->organizationService->userHasOrganization($user);
        $hasTeams = !empty($userTeams);
        $showStatistics = $hasOrganization && $hasTeams;
        
        // Use DashboardService to calculate statistics
        $stats = $showStatistics ? $this->dashboardService->calculateDashboardStats($user, $userTeams) : [];
        
        // Use DashboardService to get recent activity
        $recentActivity = $showStatistics ? $this->dashboardService->getRecentActivity($user) : [];
        
        // Use DashboardService to get upcoming deadlines
        $upcomingDeadlines = $showStatistics ? $this->dashboardService->getUpcomingDeadlines($user) : [];
        
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
}
