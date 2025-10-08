<?php

namespace App\Controller;

use App\Entity\RetrospectiveAction;
use App\Entity\User;
use App\Service\ActionService;
use App\Service\ActionFilterService;
use App\Service\TeamAccessService;
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
        private ActionService $actionService,
        private ActionFilterService $actionFilterService,
        private TeamAccessService $teamAccessService
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

        // Use TeamAccessService to get user teams
        $userTeams = $this->teamAccessService->getUserTeamsByRole($user);

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

        // Check if user has access to this team using TeamAccessService
        if (!$this->teamAccessService->hasAccessToTeam($user, $teamId)) {
            throw $this->createAccessDeniedException('You do not have access to this team');
        }

        // Get filter and pagination parameters
        $filterStatus = $request->query->get('status', 'all');
        $filterReview = $request->query->get('review', '');
        $page = $request->query->getInt('page', 1);
        $limit = $request->query->getInt('limit', 10);
        $sortField = $request->query->get('sort', 'created_at');
        $sortDirection = $request->query->get('direction', 'desc');
        
        // Use ActionFilterService to get paginated actions
        $result = $this->actionFilterService->getPaginatedActionsForTeam(
            $team,
            $filterStatus,
            $filterReview,
            $page,
            $limit,
            $sortField,
            $sortDirection
        );
        
        // Get status statistics using ActionFilterService
        $statusStats = $this->actionFilterService->getStatusStatisticsForTeam($team);

        return $this->render('actions/index.html.twig', [
            'actions' => $result['actions'],
            'team' => $team,
            'filterStatus' => $filterStatus,
            'filterReview' => $filterReview,
            'sortField' => $sortField,
            'sortDirection' => $sortDirection,
            'pagination' => $result['pagination'],
            'statusStats' => $statusStats,
        ]);
    }

    #[Route('/', name: 'app_actions_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->getUser();
        
        // Use ActionService to get actions based on user role
        $actions = $this->actionService->getActionsForUser($user);

        // Use ActionService to group actions by status
        $groupedActions = $this->actionService->groupActionsByStatus($actions);

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
        
        // Check permissions (admin, facilitator, or supervisor)
        if (!$user->hasAnyRole(['ROLE_ADMIN', 'ROLE_SUPERVISOR', 'ROLE_FACILITATOR']) &&
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
        
        // Use ActionService to get user's actions
        $actions = $this->actionService->getActionsForUser($user);

        $stats = [
            'total' => count($actions),
            'pending' => count(array_filter($actions, fn($a) => $a->getStatus() === 'pending')),
            'in_progress' => count(array_filter($actions, fn($a) => $a->getStatus() === 'in_progress')),
            'completed' => count(array_filter($actions, fn($a) => $a->getStatus() === 'completed')),
            'cancelled' => count(array_filter($actions, fn($a) => $a->getStatus() === 'cancelled')),
            'overdue' => count(array_filter($actions, fn($a) => $this->actionService->isActionOverdue($a)))
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
                    $action->getRetrospective()->getTeam()->getOwner()->getId() === $user->getId() ||
                    $this->isGranted('ROLE_ADMIN');

        if (!$hasAccess) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'message' => 'You do not have access to this action']);
            }
            throw $this->createAccessDeniedException('You do not have access to this action');
        }

        // Load context data if available
        $contextData = null;
        if ($action->getContextType() && $action->getContextId()) {
            $contextData = $this->loadContextData($action->getContextType(), $action->getContextId());
        }

        $html = $this->renderView('actions/detail_popup.html.twig', [
            'action' => $action,
            'contextData' => $contextData,
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
                        $action->getRetrospective()->getTeam()->getOwner()->getId() === $user->getId() ||
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
                        $action->getRetrospective()->getTeam()->getOwner()->getId() === $user->getId() ||
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
                        $action->getRetrospective()->getTeam()->getOwner()->getId() === $user->getId() ||
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

    #[Route('/{id}/update-assignee', name: 'app_actions_update_assignee', methods: ['POST'])]
    public function updateAssignee(Request $request, int $id): Response
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
        $hasPermission = $action->getRetrospective()->getTeam()->getOwner()->getId() === $user->getId() ||
                        $this->isGranted('ROLE_ADMIN') ||
                        $this->isGranted('ROLE_SUPERVISOR') ||
                        $this->isGranted('ROLE_FACILITATOR');

        if (!$hasPermission) {
            return $this->json(['success' => false, 'message' => 'You do not have permission to reassign this action']);
        }

        $data = json_decode($request->getContent(), true);
        $assignedToId = $data['assigned_to_id'] ?? null;

        try {
            if ($assignedToId && !empty(trim($assignedToId))) {
                $assignedUser = $this->entityManager->getRepository(\App\Entity\User::class)->find($assignedToId);
                if (!$assignedUser) {
                    return $this->json(['success' => false, 'message' => 'User not found']);
                }

                // Check if the user is a member of the retrospective team or is the owner
                $team = $action->getRetrospective()->getTeam();
                $isTeamMember = false;
                if ($team->getOwner() === $assignedUser) {
                    $isTeamMember = true;
                } else {
                    foreach ($team->getTeamMembers() as $member) {
                        if ($member->getUser() === $assignedUser) {
                            $isTeamMember = true;
                            break;
                        }
                    }
                }

                if (!$isTeamMember) {
                    return $this->json(['success' => false, 'message' => 'User is not a member of this team']);
                }

                $action->setAssignedTo($assignedUser);
            } else {
                $action->setAssignedTo(null);
            }
            
            $action->setUpdatedAt(new \DateTime());
            $this->entityManager->flush();

            $response = [
                'success' => true,
                'message' => 'Assignee updated successfully'
            ];

            // Include assignee info for frontend update
            if ($action->getAssignedTo()) {
                $response['assignee'] = [
                    'id' => $action->getAssignedTo()->getId(),
                    'email' => $action->getAssignedTo()->getEmail(),
                    'is_owner' => $action->getRetrospective()->getTeam()->getOwner() === $action->getAssignedTo()
                ];
            }

            return $this->json($response);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Error updating assignee: ' . $e->getMessage()
            ]);
        }
    }

    private function loadContextData(string $contextType, int $contextId): ?array
    {
        try {
            switch ($contextType) {
                case 'item':
                    $item = $this->entityManager->find(\App\Entity\RetrospectiveItem::class, $contextId);
                    if ($item) {
                        return [
                            'type' => 'item',
                            'data' => [
                                'id' => $item->getId(),
                                'content' => $item->getContent(),
                                'category' => $item->getCategory(),
                                'createdBy' => $item->getAuthor() ? $item->getAuthor()->getEmail() : 'Unknown'
                            ]
                        ];
                    }
                    break;
                    
                case 'group':
                    $group = $this->entityManager->find(\App\Entity\RetrospectiveGroup::class, $contextId);
                    if ($group) {
                        $items = [];
                        foreach ($group->getItems() as $item) {
                            $items[] = [
                                'id' => $item->getId(),
                                'content' => $item->getContent(),
                                'category' => $item->getCategory(),
                                'createdBy' => $item->getAuthor() ? $item->getAuthor()->getEmail() : 'Unknown'
                            ];
                        }
                        return [
                            'type' => 'group',
                            'data' => [
                                'id' => $group->getId(),
                                'items' => $items,
                                'category' => $items[0]['category'] ?? 'unknown'
                            ]
                        ];
                    }
                    break;
            }
        } catch (\Exception $e) {
            error_log('Error loading context data: ' . $e->getMessage());
        }
        
        return null;
    }

    #[Route('/{id}/mark-reviewed', name: 'app_actions_mark_reviewed', methods: ['POST'])]
    public function markAsReviewed(Request $request, int $id): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['success' => false, 'message' => 'You must be logged in to mark actions as reviewed']);
        }

        $action = $this->actionService->getActionWithAccessCheck($id, $user);
        if (!$action) {
            return $this->json(['success' => false, 'message' => 'Action not found or access denied']);
        }

        try {
            $this->actionService->markAsReviewed($action);

            return $this->json([
                'success' => true,
                'message' => 'Action marked as reviewed successfully'
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Error marking action as reviewed: ' . $e->getMessage()
            ]);
        }
    }

    #[Route('/reset-reviewed', name: 'app_actions_reset_reviewed', methods: ['POST'])]
    public function resetReviewedActions(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['success' => false, 'message' => 'You must be logged in to reset reviewed actions']);
        }

        // Check if user has permission to reset reviewed actions
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_SUPERVISOR')) {
            return $this->json(['success' => false, 'message' => 'You do not have permission to reset reviewed actions']);
        }

        try {
            // Get team ID from request
            $data = json_decode($request->getContent(), true);
            $teamId = $data['teamId'] ?? null;

            if (!$teamId) {
                return $this->json(['success' => false, 'message' => 'Team ID is required']);
            }

            // Use ActionService to reset reviewed actions for team
            $updatedCount = $this->actionService->resetReviewedActionsForTeam($teamId);

            return $this->json([
                'success' => true,
                'message' => "Successfully reset {$updatedCount} reviewed actions"
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Error resetting reviewed actions: ' . $e->getMessage()
            ]);
        }
    }

    #[Route('/{id}/delete', name: 'app_actions_delete', methods: ['POST'])]
    public function deleteAction(int $id): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['success' => false, 'message' => 'User not authenticated'], 401);
        }

        try {
            // Use ActionService to get action with access check
            $action = $this->actionService->getActionWithAccessCheck($id, $user);
            if (!$action) {
                return $this->json(['success' => false, 'message' => 'Action not found or access denied'], 404);
            }

            // Use ActionService to delete action
            $this->actionService->deleteAction($action);

            return $this->json(['success' => true, 'message' => 'Action deleted successfully']);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => 'Error deleting action: ' . $e->getMessage()], 500);
        }
    }
}
