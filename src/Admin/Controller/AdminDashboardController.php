<?php

namespace App\Admin\Controller;

use App\Admin\Repository\AdminUserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin')]
class AdminDashboardController extends AbstractController
{
    public function __construct(
        private AdminUserRepository $adminUserRepository
    ) {
    }

    #[Route('/dashboard', name: 'admin_dashboard')]
    public function dashboard(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        // Get admin statistics only
        $stats = [
            'total_admins' => $this->adminUserRepository->count([]),
            'active_admins' => count($this->adminUserRepository->findActiveAdmins()),
        ];

        // Get recent admin users
        $recentAdmins = $this->adminUserRepository->findBy(
            [],
            ['createdAt' => 'DESC'],
            10
        );

        return $this->render('admin/dashboard/index.html.twig', [
            'stats' => $stats,
            'recent_admins' => $recentAdmins,
        ]);
    }

    #[Route('/', name: 'admin_home')]
    public function home(): Response
    {
        return $this->redirectToRoute('admin_dashboard');
    }
}
