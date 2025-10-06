<?php

namespace App\Admin\Controller;

use App\Admin\Repository\AdminUserRepository;
use App\Repository\UserRepository;
use App\Repository\OrganizationRepository;
use App\Repository\TeamRepository;
use App\Repository\RetrospectiveRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin')]
class AdminDashboardController extends AbstractController
{
    public function __construct(
        private AdminUserRepository $adminUserRepository,
        private UserRepository $userRepository,
        private OrganizationRepository $organizationRepository,
        private TeamRepository $teamRepository,
        private RetrospectiveRepository $retrospectiveRepository
    ) {
    }

    #[Route('/dashboard', name: 'admin_dashboard')]
    public function dashboard(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        // Get statistics
        $stats = [
            'total_users' => $this->userRepository->count([]),
            'total_organizations' => $this->organizationRepository->count([]),
            'total_teams' => $this->teamRepository->count([]),
            'total_retrospectives' => $this->retrospectiveRepository->count([]),
            'active_admins' => count($this->adminUserRepository->findActiveAdmins()),
        ];

        // Get recent users
        $recentUsers = $this->userRepository->findBy(
            [],
            ['createdAt' => 'DESC'],
            10
        );

        // Get recent organizations
        $recentOrganizations = $this->organizationRepository->findBy(
            [],
            ['createdAt' => 'DESC'],
            10
        );

        return $this->render('admin/dashboard/index.html.twig', [
            'stats' => $stats,
            'recent_users' => $recentUsers,
            'recent_organizations' => $recentOrganizations,
        ]);
    }

    #[Route('/', name: 'admin_home')]
    public function home(): Response
    {
        return $this->redirectToRoute('admin_dashboard');
    }
}
