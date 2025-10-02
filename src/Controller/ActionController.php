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

    #[Route('/{id}/update-status', name: '_update_status', methods: ['POST'])]
    public function updateStatus(Request $request, int $id): Response
    {
        $action = $this->actionRepository->find($id);
        
        if (!$action) {
            throw $this->createNotFoundException('Action not found');
        }

        $user = $this->getUser();
        
        // Check permissions
        if (!$user->hasRole('ROLE_ADMIN') && 
            !$action->getAssignedTo()->equals($user) &&
            !$action->getRetrospective()->getFacilitator()->equals($user)) {
            throw $this->createAccessDeniedException('You do not have permission to update this action');
        }

        $data = json_decode($request->getContent(), true);
        $newStatus = $data['status'] ?? null;

        if (!in_array($newStatus, ['pending', 'in_progress', 'completed', 'cancelled'])) {
            return $this->json(['success' => false, 'message' => 'Invalid status'], 400);
        }

        $action->setStatus($newStatus);
        $action->setUpdatedAt(new \DateTime());
        
        $this->entityManager->persist($action);
        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Action status updated successfully',
            'action' => [
                'id' => $action->getId(),
                'status' => $action->getStatus(),
                'description' => $action->getDescription()
            ]
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
}
