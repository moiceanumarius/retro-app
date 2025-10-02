<?php

namespace App\Controller;

use App\Entity\RetrospectiveAction;
use App\Entity\User;
use App\Repository\RetrospectiveActionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/actions')]
class ActionController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RetrospectiveActionRepository $actionRepository
    )
    {
    }

    #[Route('/team-selection', name: 'app_actions_team_selection', methods: ['GET'])]
    public function teamSelection(): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        // Get teams where user is owner or member
        $userTeams = [];
        
        if ($this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_TEAM_LEAD') || $this->isGranted('ROLE_FACILITATOR')) {
            // For admins, team leads and facilitators, show all teams
            $userTeams = $this->entityManager->createQueryBuilder()
                ->select('t')
                ->from(\App\Entity\Team::class, 't')
                ->leftJoin('t.teamMembers', 'tm')
                ->leftJoin('tm.user', 'u')
                ->where('t.owner = :user OR u = :user')
                ->setParameter('user', $user)
                ->orderBy('t.name', 'ASC')
                ->getQuery()
                ->getResult();
        } else {
            // For regular users, only show teams they're members of
            $userTeams = $this->entityManager->createQueryBuilder()
                ->select('t')
                ->from(\App\Entity\Team::class, 't')
                ->leftJoin('t.teamMembers', 'tm')
                ->leftJoin('tm.user', 'u')
                ->where('u = :user')
                ->setParameter('user', $user)
                ->orderBy('t.name', 'ASC')
                ->getQuery()
                ->getResult();
        }

        return $this->render('actions/team_selection.html.twig', [
            'teams' => $userTeams,
        ]);
    }

    #[Route('/team/{teamId}', name: 'app_actions_by_team', methods: ['GET'])]
    public function actionsByTeam(Request $request, int $teamId): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $team = $this->entityManager->find(\App\Entity\Team::class, $teamId);
        if (!$team) {
            throw $this->createNotFoundException('Team not found');
        }

        // Check if user has access to this team
        $hasAccess = false;
        if ($this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_TEAM_LEAD') || $this->isGranted('ROLE_FACILITATOR')) {
            $hasAccess = true;
        } else {
            $hasAccess = $team->getOwner() === $user || 
                        $team->getTeamMembers()->exists(fn($tm) => $tm->getUser() === $user);
        }

        if (!$hasAccess) {
            throw $this->createAccessDeniedException('You do not have access to this team');
        }

        $filterStatus = $request->query->get('status', 'all');
        
        // Get actions for this specific team
        if ($filterStatus === 'all') {
            $actions = $this->actionRepository->findByTeam($team);
        } else {
            $actions = $this->actionRepository->findByTeamAndStatus($team, $filterStatus);
        }

        return $this->render('actions/index.html.twig', [
            'actions' => $actions,
            'team' => $team,
            'filterStatus' => $filterStatus,
        ]);
    }

    #[Route('/', name: 'app_actions_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->getUser();
        
        // Get actions based on user role and permissions
        $actions = [];
        
        if ($user->hasRole('ROLE_ADMIN')) {
            // Admin sees all actions
            $actions = $this->actionRepository->findAll();
        } elseif ($user->hasAnyRole(['ROLE_TEAM_LEAD', 'ROLE_FACILITATOR'])) {
            // Team leads and facilitators see actions from their teams
            $actions = $this->actionRepository->findByTeamLeadOrFacilitator($user);
        } else {
            // Regular users see actions assigned to them
            $actions = $this->actionRepository->findBy(['assignedTo' => $user]);
        }

        // Group actions by status
        $groupedActions = [
            'pending' => array_filter($actions, fn($action) => $action->getStatus() === 'pending'),
            'in_progress' => array_filter($actions, fn($action) => $action->getStatus() === 'in_progress'),
            'completed' => array_filter($actions, fn($action) => $action->getStatus() === 'completed'),
            'cancelled' => array_filter($actions, fn($action) => $action->getStatus() === 'cancelled'),
        ];

        return $this->render('actions/index.html.twig', [
            'actions' => $actions,
            'groupedActions' => $groupedActions,
            'page_title' => 'Action Management'
        ]);
    }


    #[Route('/{id}/assign', name: '_assign', methods: ['POST'])]
    public function assign(Request $request, int $id): Response
    {
        $action = $this->actionRepository->find($id);
        
        if (!$action) {
            throw $this->createNotFoundException('Action not found');
        }

        $user = $this->getUser();
        
        // Check permissions (admin, facilitator, or team lead)
        if (!$user->hasAnyRole(['ROLE_ADMIN', 'ROLE_TEAM_LEAD', 'ROLE_FACILITATOR']) &&
            !$action->getRetrospective()->getFacilitator()->equals($user)) {
            throw $this->createAccessDeniedException('You do not have permission to assign this action');
        }

        $data = json_decode($request->getContent(), true);
        $assignedUserId = $data['assignedToId'] ?? null;

        if ($assignedUserId) {
            $assignedUser = $this->entityManager->getRepository(User::class)->find($assignedUserId);
            
            if (!$assignedUser) {
                return $this->json(['success' => false, 'message' => 'User not found'], 404);
            }

            // Check if the user is a member of the retrospective team
            if (!$action->getRetrospective()->getTeam()->hasMember($assignedUser)) {
                return $this->json(['success' => false, 'message' => 'User is not a member of the team'], 400);
            }

            $action->setAssignedTo($assignedUser);
        } else {
            $action->setAssignedTo(null); // Unassign
        }

        $action->setUpdatedAt(new \DateTime());
        
        $this->entityManager->persist($action);
        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Action assignment updated successfully',
            'action' => [
                'id' => $action->getId(),
                'assignedTo' => $action->getAssignedTo() ? [
                    'id' => $action->getAssignedTo()->getId(),
                    'firstName' => $action->getAssignedTo()->getFirstName(),
                    'lastName' => $action->getAssignedTo()->getLastName(),
                    'email' => $action->getAssignedTo()->getEmail()
                ] : null
            ]
        ]);
    }

    #[Route('/stats', name: '_stats', methods: ['GET'])]
    public function getStats(): Response
    {
        $user = $this->getUser();
        
        // Get user's actions for stats
        $actions = [];
        if ($user->hasRole('ROLE_ADMIN')) {
            $actions = $this->actionRepository->findAll();
        } elseif ($user->hasAnyRole(['ROLE_TEAM_LEAD', 'ROLE_FACILITATOR'])) {
            $actions = $this->actionRepository->findByTeamLeadOrFacilitator($user);
        } else {
            $actions = $this->actionRepository->findBy(['assignedTo' => $user]);
        }

        $stats = [
            'total' => count($actions),
            'pending' => count(array_filter($actions, fn($a) => $a->getStatus() === 'pending')),
            'in_progress' => count(array_filter($actions, fn($a) => $a->getStatus() === 'in_progress')),
            'completed' => count(array_filter($actions, fn($a) => $a->getStatus() === 'completed')),
            'cancelled' => count(array_filter($actions, fn($a) => $a->getStatus() === 'cancelled')),
            'overdue' => count(array_filter($actions, function($action) {
                return $action->getDueDate() && 
                       $action->getDueDate() < new \DateTime() && 
                       !in_array($action->getStatus(), ['completed', 'cancelled']);
            }))
        ];

        return $this->json($stats);
    }

    #[Route('/{id}/details', name: 'app_actions_details', methods: ['GET'])]
    public function details(Request $request, int $id): Response
    {
        $user = $this->getUser();
        if (!$user) {
            throw $this->createAccessDeniedException('You must be logged in to view action details');
        }

        $action = $this->entityManager->find(\App\Entity\RetrospectiveAction::class, $id);
        if (!$action) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'message' => 'Action not found']);
            }
            throw $this->createNotFoundException('Action not found');
        }

        // Check if user has access to this action
        $hasAccess = $action->getAssignedTo() === $user || 
                    $action->getRetrospective()->getTeam()->getOwner() === $user ||
                    $this->isGranted('ROLE_ADMIN');

        if (!$hasAccess) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'message' => 'You do not have access to this action']);
            }
            throw $this->createAccessDeniedException('You do not have access to this action');
        }

        $html = $this->renderView('actions/detail_popup.html.twig', [
            'action' => $action,
        ]);

        return $this->json([
            'success' => true,
            'html' => $html
        ]);
    }

    #[Route('/{id}/edit-description', name: 'app_actions_edit_description', methods: ['POST'])]
    public function editDescription(Request $request, int $id): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['success' => false, 'message' => 'You must be logged in to edit actions']);
        }

        $action = $this->entityManager->find(\App\Entity\RetrospectiveAction::class, $id);
        if (!$action) {
            return $this->json(['success' => false, 'message' => 'Action not found']);
        }

        // Check if user has permission to edit this action
        $hasPermission = $action->getAssignedTo() === $user || 
                        $action->getRetrospective()->getTeam()->getOwner() === $user ||
                        $this->isGranted('ROLE_ADMIN');

        if (!$hasPermission) {
            return $this->json(['success' => false, 'message' => 'You do not have permission to edit this action']);
        }

        $data = json_decode($request->getContent(), true);
        if (!isset($data['description']) || empty(trim($data['description']))) {
            return $this->json(['success' => false, 'message' => 'Description cannot be empty']);
        }

        try {
            $newDescription = trim($data['description']);
            $action->setDescription($newDescription);
            $action->setUpdatedAt(new \DateTime());
            
            $this->entityManager->flush();
            
            return $this->json([
                'success' => true,
                'message' => 'Action description updated successfully'
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Error updating action: ' . $e->getMessage()
            ]);
        }
    }

    #[Route('/{id}/update-status', name: 'app_actions_update_status', methods: ['POST'])]
    public function updateStatus(Request $request, int $id): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['success' => false, 'message' => 'You must be logged in to update actions']);
        }

        $action = $this->entityManager->find(\App\Entity\RetrospectiveAction::class, $id);
        if (!$action) {
            return $this->json(['success' => false, 'message' => 'Action not found']);
        }

        // Check if user has permission to edit this action
        $hasPermission = $action->getAssignedTo() === $user ||
                        $action->getRetrospective()->getTeam()->getOwner() === $user ||
                        $this->isGranted('ROLE_ADMIN');

        if (!$hasPermission) {
            return $this->json(['success' => false, 'message' => 'You do not have permission to edit this action']);
        }

        $data = json_decode($request->getContent(), true);
        if (!isset($data['status']) || empty(trim($data['status']))) {
            return $this->json(['success' => false, 'message' => 'Status cannot be empty']);
        }

        $validStatuses = ['pending', 'in-progress', 'completed', 'cancelled'];
        $newStatus = trim($data['status']);
        
        if (!in_array($newStatus, $validStatuses)) {
            return $this->json(['success' => false, 'message' => 'Invalid status value']);
        }

        try {
            $action->setStatus($newStatus);
            $action->setUpdatedAt(new \DateTime());

            // If completed, set completion date
            if ($newStatus === 'completed' && !$action->getCompletedAt()) {
                $action->setCompletedAt(new \DateTime());
            } elseif ($newStatus !== 'completed' && $action->getCompletedAt()) {
                $action->setCompletedAt(null);
            }

            $this->entityManager->flush();

            return $this->json([
                'success' => true,
                'message' => 'Action status updated successfully'
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Error updating action status: ' . $e->getMessage()
            ]);
        }
    }

    #[Route('/{id}/update-due-date', name: 'app_actions_update_due_date', methods: ['POST'])]
    public function updateDueDate(Request $request, int $id): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['success' => false, 'message' => 'You must be logged in to update actions']);
        }

        $action = $this->entityManager->find(\App\Entity\RetrospectiveAction::class, $id);
        if (!$action) {
            return $this->json(['success' => false, 'message' => 'Action not found']);
        }

        // Check if user has permission to edit this action
        $hasPermission = $action->getAssignedTo() === $user ||
                        $action->getRetrospective()->getTeam()->getOwner() === $user ||
                        $this->isGranted('ROLE_ADMIN');

        if (!$hasPermission) {
            return $this->json(['success' => false, 'message' => 'You do not have permission to edit this action']);
        }

        $data = json_decode($request->getContent(), true);
        $dueDateValue = $data['due_date'] ?? null;

        try {
            if ($dueDateValue && !empty(trim($dueDateValue))) {
                $dueDate = new \DateTime(trim($dueDateValue));
                $action->setDueDate($dueDate);
            } else {
                $action->setDueDate(null);
            }
            
            $action->setUpdatedAt(new \DateTime());
            $this->entityManager->flush();

            return $this->json([
                'success' => true,
                'message' => 'Due date updated successfully'
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Error updating due date: ' . $e->getMessage()
            ]);
        }
    }
}
