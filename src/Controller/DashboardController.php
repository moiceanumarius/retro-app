<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(): Response
    {
        $user = $this->getUser();
        $userRoles = $user->getActiveRoles();
        
        return $this->render('dashboard/index.html.twig', [
            'user_roles' => $userRoles,
            'is_admin' => $user->hasRole('ROLE_ADMIN'),
            'is_facilitator' => $user->hasRole('ROLE_FACILITATOR'),
            'is_team_lead' => $user->hasRole('ROLE_TEAM_LEAD'),
            'is_member' => $user->hasRole('ROLE_MEMBER'),
        ]);
    }
}
