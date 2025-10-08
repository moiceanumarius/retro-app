<?php

namespace App\Controller;

use App\Entity\Retrospective;
use App\Entity\RetrospectiveItem;
use App\Entity\RetrospectiveAction;
use App\Entity\RetrospectiveGroup;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Vote;
use App\Form\RetrospectiveType;
use App\Form\RetrospectiveItemType;
use App\Form\RetrospectiveActionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use App\Service\ConnectedUsersService;
use App\Service\RetrospectiveService;
use App\Service\RetrospectiveItemService;
use App\Service\RetrospectiveGroupService;
use App\Service\VotingService;
use App\Service\TeamService;
use App\Service\OrganizationService;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Psr\Log\LoggerInterface;

class RetrospectiveController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private HubInterface $hub,
        private LoggerInterface $logger,
        private ConnectedUsersService $connectedUsersService,
        private RetrospectiveService $retrospectiveService,
        private RetrospectiveItemService $itemService,
        private RetrospectiveGroupService $groupService,
        private VotingService $votingService,
        private TeamService $teamService,
        private OrganizationService $organizationService
    ) {
    }

    #[Route('/retrospectives', name: 'app_retrospectives')]
    public function index(): Response
    {
        $user = $this->getUser();
        
        // Use RetrospectiveService to get retrospectives
        $retrospectives = $this->retrospectiveService->getRetrospectivesForUser($user);

        return $this->render('retrospective/index.html.twig', [
            'retrospectives' => $retrospectives,
        ]);
    }

    #[Route('/teams/{id}/retrospectives', name: 'app_team_retrospectives')]
    public function teamRetrospectives(int $id): Response
    {
        $user = $this->getUser();
        $team = $this->teamService->getTeamWithAccessCheck($id, $user);
        
        if (!$team) {
            throw $this->createNotFoundException('Team not found or access denied');
        }

        // Use RetrospectiveService to get retrospectives for team
        $retrospectives = $this->retrospectiveService->getRetrospectivesForTeam($team);

        return $this->render('retrospective/team.html.twig', [
            'team' => $team,
            'retrospectives' => $retrospectives,
        ]);
    }

    #[Route('/retrospectives/create', name: 'app_retrospectives_create')]
    public function create(Request $request): Response
    {
        $user = $this->getUser();
        
        // Use RetrospectiveService to check permissions
        if (!$this->retrospectiveService->canCreateRetrospectives($user)) {
            throw $this->createAccessDeniedException('Only Administrators, Supervisors, and Facilitators can create retrospectives');
        }

        // Use OrganizationService to get user teams
        $availableTeams = $this->organizationService->getUserTeamsInOrganization($user);
        
        if (empty($availableTeams)) {
            $this->addFlash('error', 'You must be part of a team to create retrospectives.');
            return $this->redirectToRoute('app_retrospectives');
        }

        $retrospective = new Retrospective();
        $retrospective->setFacilitator($user);
        
        $form = $this->createForm(RetrospectiveType::class, $retrospective);
        $form->handleRequest($request);
        
        if ($form->isSubmitted() && $form->isValid()) {
            // Use RetrospectiveService to create retrospective
            $this->retrospectiveService->createRetrospective($retrospective);
            
            $this->addFlash('success', '✅ Retrospective created successfully!');
            
            return $this->redirectToRoute('app_retrospectives_show', ['id' => $retrospective->getId()]);
        }
        
        return $this->render('retrospective/create.html.twig', [
            'form' => $form,
            'hasTeams' => !empty($availableTeams),
        ]);
    }

    #[Route('/retrospectives/{id}', name: 'app_retrospectives_show')]
    public function show(int $id): Response
    {
        $retrospective = $this->entityManager->getRepository(Retrospective::class)->find($id);
        
        if (!$retrospective) {
            throw $this->createNotFoundException('Retrospective not found');
        }

        // Check if user has access to this retrospective
        if (!$this->hasTeamAccess($retrospective->getTeam())) {
            throw $this->createAccessDeniedException('You do not have access to this retrospective');
        }

        // Use ItemService to get items
        $currentUser = $this->getUser();
        $items = $this->itemService->getItemsForRetrospective($retrospective, $currentUser);

        // Filter items by category (not in groups)
        $wrongItems = $this->itemService->filterItemsByCategory($items, 'wrong');
        $goodItems = $this->itemService->filterItemsByCategory($items, 'good');
        $improvedItems = $this->itemService->filterItemsByCategory($items, 'improved');
        $randomItems = $this->itemService->filterItemsByCategory($items, 'random');

        // Get groups
        $groups = $this->entityManager->getRepository(RetrospectiveGroup::class)
            ->findBy(['retrospective' => $retrospective], ['positionY' => 'ASC']);

        $wrongGroups = array_filter($groups, fn($group) => $group->getPositionX() == 0);
        $goodGroups = array_filter($groups, fn($group) => $group->getPositionX() == 1);
        $improvedGroups = array_filter($groups, fn($group) => $group->getPositionX() == 2);
        $randomGroups = array_filter($groups, fn($group) => $group->getPositionX() == 3);

        // Use GroupService to combine and sort items and groups
        $wrongCombined = $this->groupService->combineAndSortByPosition($wrongItems, $wrongGroups);
        $goodCombined = $this->groupService->combineAndSortByPosition($goodItems, $goodGroups);
        $improvedCombined = $this->groupService->combineAndSortByPosition($improvedItems, $improvedGroups);
        $randomCombined = $this->groupService->combineAndSortByPosition($randomItems, $randomGroups);

        // Get actions
        $actions = $this->entityManager->getRepository(RetrospectiveAction::class)
            ->findBy(['retrospective' => $retrospective], ['createdAt' => 'ASC']);

        // Use VotingService to get items with aggregated votes
        $sortedItemsWithVotes = [];
        if ($retrospective->isInStep('actions') || $retrospective->isCompleted()) {
            $sortedItemsWithVotes = $this->votingService->getItemsWithAggregatedVotes($items, $groups);
        }

        // Get connected users
        $connectedUsers = $this->connectedUsersService->getConnectedUsers($id);

        // Use VotingService to calculate remaining votes
        $remainingVotes = $this->votingService->calculateRemainingVotes($retrospective, $currentUser);

        return $this->render('retrospective/show.html.twig', [
            'retrospective' => $retrospective,
            'wrongItems' => $wrongItems,
            'goodItems' => $goodItems,
            'improvedItems' => $improvedItems,
            'randomItems' => $randomItems,
            'wrongGroups' => $wrongGroups,
            'goodGroups' => $goodGroups,
            'improvedGroups' => $improvedGroups,
            'randomGroups' => $randomGroups,
            'wrongCombined' => $wrongCombined,
            'goodCombined' => $goodCombined,
            'improvedCombined' => $improvedCombined,
            'randomCombined' => $randomCombined,
            'sortedItemsWithVotes' => $sortedItemsWithVotes,
            'actions' => $actions,
            'connectedUsers' => $connectedUsers,
            'remainingVotes' => $remainingVotes,
        ]);
    }

    #[Route('/retrospectives/{id}/edit', name: 'app_retrospectives_edit')]
    public function edit(Request $request, int $id): Response
    {
        $retrospective = $this->entityManager->getRepository(Retrospective::class)->find($id);
        
        if (!$retrospective) {
            throw $this->createNotFoundException('Retrospective not found');
        }

        $user = $this->getUser();
        
        // Use RetrospectiveService to check permissions
        if (!$this->retrospectiveService->canManageRetrospective($retrospective, $user)) {
            throw $this->createAccessDeniedException('Only the facilitator or team owner can edit this retrospective');
        }

        $form = $this->createForm(RetrospectiveType::class, $retrospective);
        $form->handleRequest($request);
        
        if ($form->isSubmitted() && $form->isValid()) {
            // Use RetrospectiveService to update
            $this->retrospectiveService->updateRetrospective($retrospective);
            
            $this->addFlash('success', '✅ Retrospective updated successfully!');
            
            return $this->redirectToRoute('app_retrospectives');
        }
        
        return $this->render('retrospective/edit.html.twig', [
            'retrospective' => $retrospective,
            'form' => $form,
        ]);
    }

    #[Route('/retrospectives/{id}/start', name: 'app_retrospectives_start')]
    public function start(int $id): Response
    {
        $retrospective = $this->entityManager->getRepository(Retrospective::class)->find($id);
        
        if (!$retrospective) {
            throw $this->createNotFoundException('Retrospective not found');
        }

        $user = $this->getUser();
        
        // Use RetrospectiveService to check if user is facilitator
        if (!$this->retrospectiveService->isFacilitator($retrospective, $user)) {
            throw $this->createAccessDeniedException('Only the facilitator can start this retrospective');
        }

        // Use RetrospectiveService to start retrospective
        $this->retrospectiveService->startRetrospective($retrospective);
        
        $this->addFlash('success', '✅ Retrospective started!');
        
        return $this->redirectToRoute('app_retrospectives_show', ['id' => $retrospective->getId()]);
    }

    #[Route('/retrospectives/{id}/complete', name: 'app_retrospectives_complete', methods: ['GET', 'POST'])]
    public function complete(Request $request, int $id): Response
    {
        $retrospective = $this->entityManager->getRepository(Retrospective::class)->find($id);
        
        if (!$retrospective) {
            throw $this->createNotFoundException('Retrospective not found');
        }

        $user = $this->getUser();
        
        // Use RetrospectiveService to check if user is facilitator
        if (!$this->retrospectiveService->isFacilitator($retrospective, $user)) {
            throw $this->createAccessDeniedException('Only the facilitator can complete this retrospective');
        }

        // Use RetrospectiveService to complete retrospective
        $this->retrospectiveService->completeRetrospective($retrospective);
        
        // Check if this is an AJAX request
        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'success' => true,
                'message' => 'Retrospective completed successfully!'
            ]);
        }
        
        // For regular requests, add flash message and redirect
        $this->addFlash('success', '✅ Retrospective completed!');
        
        return $this->redirectToRoute('app_retrospectives_show', ['id' => $retrospective->getId()]);
    }

    #[Route('/retrospectives/{id}/add-item', name: 'app_retrospectives_add_item')]
    public function addItem(Request $request, int $id): Response
    {
        $retrospective = $this->entityManager->getRepository(Retrospective::class)->find($id);
        
        if (!$retrospective) {
            throw $this->createNotFoundException('Retrospective not found');
        }

        // Check if user has access to this retrospective
        if (!$this->hasTeamAccess($retrospective->getTeam())) {
            throw $this->createAccessDeniedException('You do not have access to this retrospective');
        }

        // Only active retrospectives in feedback step can have items added
        if (!$retrospective->isActive() || !$retrospective->isInStep('feedback')) {
            throw $this->createAccessDeniedException('Items can only be added during the feedback phase');
        }

        $item = new RetrospectiveItem();
        $item->setRetrospective($retrospective);
        $item->setAuthor($this->getUser());
        
        $form = $this->createForm(RetrospectiveItemType::class, $item);
        $form->handleRequest($request);
        
        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($item);
            $this->entityManager->flush();
            
            $this->addFlash('success', '✅ Item added successfully!');
            
            return $this->redirectToRoute('app_retrospectives_show', ['id' => $retrospective->getId()]);
        }
        
        return $this->render('retrospective/add_item.html.twig', [
            'retrospective' => $retrospective,
            'form' => $form,
        ]);
    }

    #[Route('/retrospectives/{id}/actions', name: 'app_retrospectives_actions', methods: ['GET'])]
    public function getActions(int $id): Response
    {
        $retrospective = $this->entityManager->getRepository(Retrospective::class)->find($id);
        
        if (!$retrospective) {
            return $this->json(['error' => 'Retrospective not found'], 404);
        }

        // Check if user has access to this retrospective
        if (!$this->hasTeamAccess($retrospective->getTeam())) {
            return $this->json(['error' => 'Access denied'], 403);
        }

        $actions = $this->entityManager->getRepository(RetrospectiveAction::class)
            ->findBy(['retrospective' => $retrospective], ['createdAt' => 'DESC']);

        $actionsData = array_map(function($action) {
            return [
                'id' => $action->getId(),
                'description' => $action->getDescription(),
                'status' => $action->getStatus(),
                'dueDate' => $action->getDueDate() ? $action->getDueDate()->format('Y-m-d') : null,
                'assignedTo' => $action->getAssignedTo() ? [
                    'id' => $action->getAssignedTo()->getId(),
                    'firstName' => $action->getAssignedTo()->getFirstName(),
                    'lastName' => $action->getAssignedTo()->getLastName(),
                ] : null,
                'createdBy' => [
                    'id' => $action->getCreatedBy()->getId(),
                    'firstName' => $action->getCreatedBy()->getFirstName(),
                    'lastName' => $action->getCreatedBy()->getLastName(),
                ],
                'contextType' => $action->getContextType(),
                'contextId' => $action->getContextId(),
            ];
        }, $actions);

        return $this->json($actionsData);
    }

    #[Route('/retrospectives/actions/{actionId}/update', name: 'app_retrospectives_update_action', methods: ['POST'])]
    public function updateAction(Request $request, int $actionId): Response
    {
        $action = $this->entityManager->getRepository(RetrospectiveAction::class)->find($actionId);
        
        if (!$action) {
            return $this->json(['success' => false, 'message' => 'Action not found'], 404);
        }

        // Check if user has access to this retrospective
        if (!$this->hasTeamAccess($action->getRetrospective()->getTeam())) {
            return $this->json(['success' => false, 'message' => 'Access denied'], 403);
        }

        // Only facilitator, team owner, or action creator can update actions
        $user = $this->getUser();
        if ($action->getRetrospective()->getFacilitator() !== $user && 
            $action->getRetrospective()->getTeam()->getOwner() !== $user &&
            $action->getCreatedBy() !== $user) {
            return $this->json(['success' => false, 'message' => 'You can only update actions you created or if you are the facilitator/owner'], 403);
        }

        $data = json_decode($request->getContent(), true);
        $field = $data['field'] ?? null;
        $value = $data['value'] ?? null;

        if (!$field) {
            return $this->json(['success' => false, 'message' => 'Field is required'], 400);
        }

        try {
            switch ($field) {
                case 'description':
                    $action->setDescription($value);
                    break;
                    
                case 'status':
                    if (!in_array($value, ['pending', 'in_progress', 'completed'])) {
                        return $this->json(['success' => false, 'message' => 'Invalid status'], 400);
                    }
                    $action->setStatus($value);
                    break;
                    
                case 'dueDate':
                    if ($value) {
                        $action->setDueDate(new \DateTime($value));
                    } else {
                        $action->setDueDate(null);
                    }
                    break;
                    
                case 'assignedTo':
                    if ($value) {
                        $user = $this->entityManager->getRepository(User::class)->find($value);
                        if (!$user) {
                            return $this->json(['success' => false, 'message' => 'User not found'], 400);
                        }
                        $action->setAssignedTo($user);
                    } else {
                        $action->setAssignedTo(null);
                    }
                    break;
                    
                default:
                    return $this->json(['success' => false, 'message' => 'Invalid field'], 400);
            }

            $this->entityManager->flush();

            return $this->json(['success' => true, 'message' => 'Action updated successfully']);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => 'Failed to update action'], 500);
        }
    }

    #[Route('/retrospectives/actions/{actionId}/delete', name: 'app_retrospectives_delete_action', methods: ['POST'])]
    public function deleteAction(Request $request, int $actionId): Response
    {
        $action = $this->entityManager->getRepository(RetrospectiveAction::class)->find($actionId);
        
        if (!$action) {
            return $this->json(['success' => false, 'message' => 'Action not found'], 404);
        }

        // Check if user has access to this retrospective
        if (!$this->hasTeamAccess($action->getRetrospective()->getTeam())) {
            return $this->json(['success' => false, 'message' => 'Access denied'], 403);
        }

        // Only facilitator, team owner, or action creator can delete actions
        $user = $this->getUser();
        if ($action->getRetrospective()->getFacilitator() !== $user && 
            $action->getRetrospective()->getTeam()->getOwner() !== $user &&
            $action->getCreatedBy() !== $user) {
            return $this->json(['success' => false, 'message' => 'You can only delete actions you created or if you are the facilitator/owner'], 403);
        }

        try {
            $this->entityManager->remove($action);
            $this->entityManager->flush();

            return $this->json(['success' => true, 'message' => 'Action deleted successfully']);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => 'Failed to delete action'], 500);
        }
    }

    #[Route('/retrospectives/{id}/add-action', name: 'app_retrospectives_add_action', methods: ['POST', 'GET'])]
    public function addAction(Request $request, int $id): Response
    {
        $retrospective = $this->entityManager->getRepository(Retrospective::class)->find($id);
        
        if (!$retrospective) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'message' => 'Retrospective not found'], 404);
            }
            throw $this->createNotFoundException('Retrospective not found');
        }

        // Check if user has access to this retrospective
        if (!$this->hasTeamAccess($retrospective->getTeam())) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'message' => 'Access denied'], 403);
            }
            throw $this->createAccessDeniedException('You do not have access to this retrospective');
        }

        // Only facilitator or team owner can add actions
        if ($retrospective->getFacilitator() !== $this->getUser() && $retrospective->getTeam()->getOwner() !== $this->getUser()) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'message' => 'Only the facilitator or team owner can add actions'], 403);
            }
            throw $this->createAccessDeniedException('Only the facilitator or team owner can add actions');
        }

        // Handle AJAX request
        if ($request->isXmlHttpRequest() && $request->getMethod() === 'POST') {
            $data = json_decode($request->getContent(), true);
            $description = $data['description'] ?? null;
            $assignedToId = $data['assignedToId'] ?? null;
            $dueDate = $data['dueDate'] ?? null;
            $contextType = $data['contextType'] ?? null;
            $contextId = $data['contextId'] ?? null;

            if (!$description) {
                return $this->json(['success' => false, 'message' => 'Description is required'], 400);
            }

            // DEBUG: Log the request data
            $this->logger->info("DEBUG Add Action - Description: " . $description);
            $this->logger->info("DEBUG Add Action - AssignedToId raw: " . var_export($assignedToId, true));
            $this->logger->info("DEBUG Add Action - AssignedToId type: " . gettype($assignedToId));
            $this->logger->info("DEBUG Add Action - User: " . ($this->getUser() ? $this->getUser()->getEmail() : 'null'));

            $action = new RetrospectiveAction();
            $action->setRetrospective($retrospective);
            $action->setCreatedBy($this->getUser());
            $action->setDescription($description);
            
            // Set team and sprint ID
            $action->setTeam($retrospective->getTeam());
            $action->setSprintId($retrospective->getId()); // Using retrospective ID as sprint ID
            
            // Set context (reference to card or group)
            if ($contextType && $contextId) {
                $action->setContextType($contextType); // 'item' or 'group'
                $action->setContextId($contextId);
            }
            
            // Assignee is optional
            if ($assignedToId && $assignedToId !== '' && $assignedToId !== '0') {
                $this->logger->info("DEBUG Add Action - Looking up user ID: " . $assignedToId);
                $assignedTo = $this->entityManager->getRepository(User::class)->find($assignedToId);
                if (!$assignedTo) {
                    $this->logger->error("DEBUG Add Action - User not found for ID: " . $assignedToId);
                    return $this->json(['success' => false, 'message' => 'Assigned user not found'], 404);
                }
                $action->setAssignedTo($assignedTo);
                $this->logger->info("DEBUG Add Action - ASSIGNED to user ID: " . $assignedTo->getId());
            } else {
                $currentUser = $this->getUser();
                $this->logger->info("DEBUG Add Action - Current User: " . ($currentUser ? 'ID ' . $currentUser->getId() : 'NULL'));
                
                // If no assignee, assign to the creator by default
                $action->setAssignedTo($currentUser);
                $this->logger->info("DEBUG Add Action - Action assignedTo set to: " . ($action->getAssignedTo() ? 'ID ' . $action->getAssignedTo()->getId() : 'NULL'));
            }
            
            if ($dueDate) {
                $action->setDueDate(new \DateTime($dueDate));
            }

            $this->logger->info("DEBUG Add Action - About to persist action...");
            $this->entityManager->persist($action);
            $this->logger->info("DEBUG Add Action - Persisted, about to flush...");
            
            try {
                $this->entityManager->flush();
                $this->logger->info("DEBUG Add Action - Flush successful!");
            } catch (\Exception $e) {
                $this->logger->error("DEBUG Add Action - FLUSH ERROR: " . $e->getMessage());
                throw $e;
            }

            return $this->json([
                'success' => true,
                'message' => 'Action item added successfully',
                'action' => [
                    'id' => $action->getId(),
                    'description' => $action->getDescription(),
                    'assignedTo' => $action->getAssignedTo()->getFirstName() . ' ' . $action->getAssignedTo()->getLastName(),
                    'dueDate' => $action->getDueDate() ? $action->getDueDate()->format('M d, Y') : null,
                    'status' => $action->getStatus()
                ]
            ]);
        }

        // Handle traditional form submission
        $action = new RetrospectiveAction();
        $action->setRetrospective($retrospective);
        $action->setCreatedBy($this->getUser());
        
        $form = $this->createForm(RetrospectiveActionType::class, $action);
        $form->handleRequest($request);
        
        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($action);
            $this->entityManager->flush();
            
            $this->addFlash('success', '✅ Action item added successfully!');
            
            return $this->redirectToRoute('app_retrospectives_show', ['id' => $retrospective->getId()]);
        }
        
        return $this->render('retrospective/add_action.html.twig', [
            'retrospective' => $retrospective,
            'form' => $form,
        ]);
    }

    #[Route('/retrospectives/{id}/start-timer', name: 'app_retrospectives_start_timer', methods: ['POST'])]
    public function startTimer(Request $request, int $id): Response
    {
        $retrospective = $this->entityManager->getRepository(Retrospective::class)->find($id);
        
        if (!$retrospective) {
            throw $this->createNotFoundException('Retrospective not found');
        }

        $user = $this->getUser();

        // Use RetrospectiveService to check if user is facilitator
        if (!$this->retrospectiveService->isFacilitator($retrospective, $user)) {
            throw $this->createAccessDeniedException('Only the facilitator can start the timer');
        }

        $data = json_decode($request->getContent(), true);
        $duration = $data['duration'] ?? 10;
        
        // Use RetrospectiveService to start timer
        $this->retrospectiveService->startTimer($retrospective, (int)$duration);
        
        return $this->json([
            'success' => true,
            'duration' => $duration,
            'startedAt' => $retrospective->getTimerStartedAt()->format('Y-m-d H:i:s')
        ]);
    }

    #[Route('/retrospectives/{id}/stop-timer', name: 'app_retrospectives_stop_timer', methods: ['POST'])]
    public function stopTimer(Request $request, int $id): Response
    {
        $retrospective = $this->entityManager->getRepository(Retrospective::class)->find($id);
        
        if (!$retrospective) {
            throw $this->createNotFoundException('Retrospective not found');
        }

        $user = $this->getUser();

        // Use RetrospectiveService to check if user is facilitator
        if (!$this->retrospectiveService->isFacilitator($retrospective, $user)) {
            throw $this->createAccessDeniedException('Only the facilitator can stop the timer');
        }

        // Use RetrospectiveService to stop timer
        $this->retrospectiveService->stopTimer($retrospective);
        
        return $this->json([
            'success' => true,
            'message' => 'Timer stopped successfully'
        ]);
    }

    #[Route('/retrospectives/{id}/next-step', name: 'app_retrospectives_next_step', methods: ['POST'])]
    public function nextStep(int $id): Response
    {
        $retrospective = $this->entityManager->getRepository(Retrospective::class)->find($id);
        
        if (!$retrospective) {
            return $this->json(['success' => false, 'message' => 'Retrospective not found'], 404);
        }

        $user = $this->getUser();
        
        // Use RetrospectiveService to check if user is facilitator
        if (!$this->retrospectiveService->isFacilitator($retrospective, $user)) {
            return $this->json(['success' => false, 'message' => 'Only the facilitator can move to the next step'], 403);
        }

        // Use RetrospectiveService to move to next step
        $nextStep = $this->retrospectiveService->moveToNextStep($retrospective);
        
        return $this->json([
            'success' => true,
            'message' => 'Moved to next step: ' . ucfirst($nextStep),
            'nextStep' => $nextStep
        ]);
    }

    #[Route('/retrospectives/{id}/add-item-ajax', name: 'app_retrospectives_add_item_ajax', methods: ['POST'])]
    public function addItemAjax(Request $request, int $id): Response
    {
        $retrospective = $this->entityManager->getRepository(Retrospective::class)->find($id);
        
        if (!$retrospective) {
            return $this->json(['success' => false, 'message' => 'Retrospective not found'], 404);
        }

        // Check access
        if (!$this->hasTeamAccess($retrospective->getTeam())) {
            return $this->json(['success' => false, 'message' => 'Access denied'], 403);
        }

        // Only active retrospectives in feedback step can have items added
        if (!$retrospective->isActive() || !$retrospective->isInStep('feedback')) {
            return $this->json(['success' => false, 'message' => 'Items can only be added during the feedback phase'], 400);
        }

        $data = json_decode($request->getContent(), true);
        $content = $data['content'] ?? null;
        $category = $data['category'] ?? null;

        if (!$content || !$category) {
            return $this->json(['success' => false, 'message' => 'Content and category are required'], 400);
        }

        try {
            // Use ItemService to create item
            $item = $this->itemService->createItem($retrospective, $this->getUser(), $content, $category);
            
            return $this->json([
                'success' => true,
                'item' => [
                    'id' => $item->getId(),
                    'content' => $item->getContent(),
                    'category' => $item->getCategory(),
                    'author' => [
                        'firstName' => $item->getAuthor()->getFirstName(),
                        'lastName' => $item->getAuthor()->getLastName()
                    ],
                    'createdAt' => $item->getCreatedAt()->format('H:i')
                ]
            ]);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => 'Failed to create item'], 500);
        }
    }

    #[Route('/retrospectives/{id}/update-item-ajax', name: 'app_retrospectives_update_item_ajax', methods: ['POST'])]
    public function updateItemAjax(Request $request, int $id): Response
    {
        $retrospective = $this->entityManager->getRepository(Retrospective::class)->find($id);
        
        if (!$retrospective) {
            return $this->json(['success' => false, 'message' => 'Retrospective not found'], 404);
        }

        // Check access
        if (!$this->hasTeamAccess($retrospective->getTeam())) {
            return $this->json(['success' => false, 'message' => 'Access denied'], 403);
        }

        $data = json_decode($request->getContent(), true);
        $itemId = $data['itemId'] ?? null;
        $content = $data['content'] ?? null;

        if (!$itemId || !$content) {
            return $this->json(['success' => false, 'message' => 'Item ID and content are required'], 400);
        }

        $item = $this->entityManager->getRepository(RetrospectiveItem::class)->find($itemId);
        
        if (!$item) {
            return $this->json(['success' => false, 'message' => 'Item not found'], 404);
        }

        $currentUser = $this->getUser();
        
        // Use ItemService to check if user can edit
        if (!$this->itemService->canEditItem($item, $currentUser)) {
            return $this->json(['success' => false, 'message' => 'You can only edit your own items'], 403);
        }

        // Use ItemService to update item
        $this->itemService->updateItem($item, $content);
        
        return $this->json([
            'success' => true,
            'message' => 'Item updated successfully'
        ]);
    }

    #[Route('/retrospectives/{id}/delete-item-ajax', name: 'app_retrospectives_delete_item_ajax', methods: ['POST'])]
    public function deleteItemAjax(Request $request, int $id): Response
    {
        $retrospective = $this->entityManager->getRepository(Retrospective::class)->find($id);
        
        if (!$retrospective) {
            return $this->json(['success' => false, 'message' => 'Retrospective not found'], 404);
        }

        // Check access
        if (!$this->hasTeamAccess($retrospective->getTeam())) {
            return $this->json(['success' => false, 'message' => 'Access denied'], 403);
        }

        $data = json_decode($request->getContent(), true);
        $itemId = $data['itemId'] ?? null;

        if (!$itemId) {
            return $this->json(['success' => false, 'message' => 'Item ID is required'], 400);
        }

        $item = $this->entityManager->getRepository(RetrospectiveItem::class)->find($itemId);
        
        if (!$item) {
            return $this->json(['success' => false, 'message' => 'Item not found'], 404);
        }

        $currentUser = $this->getUser();
        
        // Use ItemService to check if user can edit (same as delete permission)
        if (!$this->itemService->canEditItem($item, $currentUser)) {
            return $this->json(['success' => false, 'message' => 'You can only delete your own items'], 403);
        }

        // Use ItemService to delete item
        $this->itemService->deleteItem($item);
        
        return $this->json([
            'success' => true,
            'message' => 'Item deleted successfully'
        ]);
    }

    #[Route('/retrospectives/{id}/review-data', name: 'app_retrospectives_review_data', methods: ['GET'])]
    public function getReviewData(int $id): Response
    {
        // error_log("getReviewData called for retrospective ID: $id");
        // error_log("Current user: " . ($this->getUser() ? $this->getUser()->getEmail() : 'null'));

        $retrospective = $this->entityManager->getRepository(Retrospective::class)->find($id);

        if (!$retrospective) {
            // error_log("Retrospective not found for ID: $id");
            return $this->json(['success' => false, 'message' => 'Retrospective not found'], 404);
        }

        if (!$this->hasTeamAccess($retrospective->getTeam())) {
            // error_log("Access denied for retrospective ID: $id");
            return $this->json(['success' => false, 'message' => 'Access denied'], 403);
        }

        // error_log("Getting items and groups for retrospective ID: $id");

        // Get all items and groups for the review step, ordered by position
        $items = $this->entityManager->getRepository(RetrospectiveItem::class)
            ->findBy(['retrospective' => $retrospective], ['position' => 'ASC']);

        // error_log("Found " . count($items) . " items");

        $groups = $this->entityManager->getRepository(RetrospectiveGroup::class)
            ->findBy(['retrospective' => $retrospective], ['position' => 'ASC']);

        // error_log("Found " . count($groups) . " groups");

        // Debug: log items by category
        $itemsByCategory = ['wrong' => 0, 'good' => 0, 'improved' => 0, 'random' => 0];
        foreach ($items as $item) {
            if (isset($itemsByCategory[$item->getCategory()])) {
                $itemsByCategory[$item->getCategory()]++;
            }
        }
        // error_log("Items by category: " . json_encode($itemsByCategory));

        // error_log("About to return JSON response");

        return $this->json([
            'success' => true,
            'items' => array_map(function($item) {
                return [
                    'id' => $item->getId(),
                    'content' => $item->getContent(),
                    'category' => $item->getCategory(),
                    'position' => $item->getPosition(),
                    'group_id' => $item->getGroup() ? $item->getGroup()->getId() : null,
                ];
            }, $items),
               'groups' => array_map(function($group) {
                   return [
                       'id' => $group->getId(),
                       'title' => $group->getTitle(),
                       'description' => $group->getDescription(),
                       'position' => $group->getPosition(),
                       'display_category' => $group->getDisplayCategory(),
                       'item_count' => $group->getItems()->count(),
                       'items' => array_map(function($item) {
                           return [
                               'id' => $item->getId(),
                               'content' => $item->getContent(),
                               'category' => $item->getCategory(),
                           ];
                       }, $group->getItems()->toArray())
                   ];
               }, $groups)
        ]);
    }

    #[Route('/retrospectives/{id}/create-group', name: 'app_retrospectives_create_group', methods: ['POST'])]
    public function createGroup(Request $request, int $id): Response
    {
        $retrospective = $this->entityManager->getRepository(Retrospective::class)->find($id);
        
        if (!$retrospective) {
            return $this->json(['success' => false, 'message' => 'Retrospective not found'], 404);
        }

        // Manual CSRF validation
        $data = json_decode($request->getContent(), true);
        $token = $data['_token'] ?? $request->headers->get('X-CSRF-Token');
        
        if (!$token || !$this->isCsrfTokenValid('retrospective_action', $token)) {
            return $this->json(['success' => false, 'message' => 'Invalid CSRF token'], 403);
        }

        if (!$this->hasTeamAccess($retrospective->getTeam())) {
            return $this->json(['success' => false, 'message' => 'Access denied'], 403);
        }

        // Only facilitator can create groups
        if ($retrospective->getFacilitator() !== $this->getUser()) {
            return $this->json(['success' => false, 'message' => 'Only facilitator can create groups'], 403);
        }
        $itemIds = $data['itemIds'] ?? [];
        $category = $data['category'] ?? '';
        $targetPosition = $data['targetPosition'] ?? null;

        if (empty($itemIds) || count($itemIds) < 2) {
            return $this->json(['success' => false, 'message' => 'At least 2 items are required to create a group'], 400);
        }

        // Create new group
        $group = new RetrospectiveGroup();
        $group->setRetrospective($retrospective);
        
        // Set positionX based on category
        $positionX = match($category) {
            'wrong' => 0,
            'good' => 1,
            'improved' => 2,
            'random' => 3,
            default => 0
        };
        $group->setPositionX($positionX);
        $group->setPositionY(0); // Will be set later based on position in column
        $group->setTitle('Group ' . (count($retrospective->getGroups()) + 1));
        $group->setDisplayCategory($category); // Set the display category to the target column
        
        // Set position based on target position or to the end of the column
        if ($targetPosition !== null) {
            // Insert at the specified position
            $group->setPosition($targetPosition);
            
            // Shift other groups down to make room
            $this->entityManager->getRepository(RetrospectiveGroup::class)
                ->createQueryBuilder('g')
                ->update()
                ->set('g.position', 'g.position + 1')
                ->where('g.retrospective = :retrospective')
                ->andWhere('g.displayCategory = :category')
                ->andWhere('g.position >= :position')
                ->setParameter('retrospective', $retrospective)
                ->setParameter('category', $category)
                ->setParameter('position', $targetPosition)
                ->getQuery()
                ->execute();
        } else {
            // Set position to the end of the column
            $existingGroups = $this->entityManager->getRepository(RetrospectiveGroup::class)
                ->findBy(['retrospective' => $retrospective, 'displayCategory' => $category], ['position' => 'DESC'], 1);
            $group->setPosition($existingGroups ? $existingGroups[0]->getPosition() + 1 : 0);
        }

        $this->entityManager->persist($group);

        // Add items to group
        foreach ($itemIds as $itemId) {
            $item = $this->entityManager->getRepository(RetrospectiveItem::class)->find($itemId);
            if ($item && $item->getRetrospective() === $retrospective) {
                $group->addItem($item);
                
                // Delete individual votes on this item since it's now part of a group
                $itemVotes = $this->entityManager->getRepository(Vote::class)->findBy([
                    'retrospectiveItem' => $item
                ]);
                foreach ($itemVotes as $vote) {
                    $this->entityManager->remove($vote);
                }
            }
        }

        $this->entityManager->flush();

        // Broadcast update to all connected clients
        $update = new Update(
            "retrospective/{$id}/review",
            json_encode([
                'type' => 'group_created',
                'group' => [
                    'id' => $group->getId(),
                    'title' => $group->getTitle(),
                    'position_x' => $group->getPositionX(),
                    'position_y' => $group->getPositionY(),
                    'item_count' => $group->getItems()->count(),
                ],
                'item_ids' => $itemIds
            ])
        );
        $this->hub->publish($update);
        
        // Also broadcast to general retrospective topic
        $update2 = new Update(
            "retrospective/{$id}",
            json_encode([
                'type' => 'group_created',
                'group' => [
                    'id' => $group->getId(),
                    'title' => $group->getTitle(),
                    'position_x' => $group->getPositionX(),
                    'position_y' => $group->getPositionY(),
                    'item_count' => $group->getItems()->count(),
                ],
                'item_ids' => $itemIds
            ])
        );
        $this->hub->publish($update2);

        return $this->json([
            'success' => true,
            'message' => 'Group created successfully',
            'group' => [
                'id' => $group->getId(),
                'title' => $group->getTitle(),
                'position_x' => $group->getPositionX(),
                'position_y' => $group->getPositionY(),
                'item_count' => $group->getItems()->count(),
            ]
        ]);
    }

    #[Route('/retrospectives/{id}/update-group-position', name: 'app_retrospectives_update_group_position', methods: ['POST'])]
    public function updateGroupPosition(Request $request, int $id): Response
    {
        $retrospective = $this->entityManager->getRepository(Retrospective::class)->find($id);
        
        if (!$retrospective) {
            return $this->json(['success' => false, 'message' => 'Retrospective not found'], 404);
        }

        if (!$this->hasTeamAccess($retrospective->getTeam())) {
            return $this->json(['success' => false, 'message' => 'Access denied'], 403);
        }

        // Only facilitator can update group positions
        if ($retrospective->getFacilitator() !== $this->getUser()) {
            return $this->json(['success' => false, 'message' => 'Only facilitator can update group positions'], 403);
        }

        $data = json_decode($request->getContent(), true);
        $groupId = $data['groupId'] ?? null;
        $positionX = $data['positionX'] ?? null;
        $positionY = $data['positionY'] ?? null;

        if (!$groupId || $positionX === null || $positionY === null) {
            return $this->json(['success' => false, 'message' => 'Group ID and positions are required'], 400);
        }

        $group = $this->entityManager->getRepository(RetrospectiveGroup::class)->find($groupId);
        if (!$group || $group->getRetrospective() !== $retrospective) {
            return $this->json(['success' => false, 'message' => 'Group not found'], 404);
        }

        $group->setPositionX($positionX);
        $group->setPositionY($positionY);
        $group->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->flush();

        // Broadcast update to all connected clients
        $update = new Update(
            "retrospective/{$id}/review",
            json_encode([
                'type' => 'group_moved',
                'group_id' => $groupId,
                'position_x' => $positionX,
                'position_y' => $positionY
            ])
        );
        $this->hub->publish($update);

        return $this->json([
            'success' => true,
            'message' => 'Group position updated successfully'
        ]);
    }

    #[Route('/retrospectives/{id}/separate-item', name: 'app_retrospectives_separate_item', methods: ['POST'])]
    public function separateItem(Request $request, int $id): Response
    {
        $retrospective = $this->entityManager->getRepository(Retrospective::class)->find($id);

        if (!$retrospective) {
            return $this->json(['success' => false, 'message' => 'Retrospective not found'], 404);
        }

        if (!$this->hasTeamAccess($retrospective->getTeam())) {
            return $this->json(['success' => false, 'message' => 'Access denied'], 403);
        }

        // Only facilitators can separate items
        if ($retrospective->getFacilitator() !== $this->getUser()) {
            return $this->json(['success' => false, 'message' => 'Only facilitators can separate items'], 403);
        }

        // Manual CSRF validation
        $data = json_decode($request->getContent(), true);
        $token = $data['_token'] ?? $request->headers->get('X-CSRF-Token');
        
        if (!$token || !$this->isCsrfTokenValid('retrospective_action', $token)) {
            return $this->json(['success' => false, 'message' => 'Invalid CSRF token'], 403);
        }
        $itemId = $data['itemId'] ?? null;

        if (!$itemId) {
            return $this->json(['success' => false, 'message' => 'Item ID is required'], 400);
        }

        $item = $this->entityManager->getRepository(RetrospectiveItem::class)->find($itemId);
        if (!$item || $item->getRetrospective() !== $retrospective) {
            return $this->json(['success' => false, 'message' => 'Item not found'], 404);
        }

        $group = $item->getGroup();
        if (!$group) {
            return $this->json(['success' => false, 'message' => 'Item is not in a group'], 400);
        }

        // Remove item from group
        $group->removeItem($item);
        $item->setGroup(null);

        // If group has no more items, delete it
        // If group has only one item left, also delete it (groups need at least 2 items)
        if ($group->getItems()->count() <= 1) {
            // If there's one item left, remove it from the group too
            if ($group->getItems()->count() === 1) {
                $remainingItem = $group->getItems()->first();
                $group->removeItem($remainingItem);
                $remainingItem->setGroup(null);
            }
            $this->entityManager->remove($group);
        }

        $this->entityManager->flush();

        // Broadcast update to all connected clients
        $update = new Update(
            "retrospective/{$id}/review",
            json_encode([
                'type' => 'item_separated',
                'item_id' => $itemId,
                'group_id' => $group->getId()
            ])
        );
        $this->hub->publish($update);

        return $this->json([
            'success' => true,
            'message' => 'Item separated successfully'
        ]);
    }

    #[Route('/retrospectives/{id}/add-item-to-group', name: 'app_retrospectives_add_item_to_group', methods: ['POST'])]
    public function addItemToGroup(Request $request, int $id): Response
    {
        $retrospective = $this->entityManager->getRepository(Retrospective::class)->find($id);

        if (!$retrospective) {
            return $this->json(['success' => false, 'message' => 'Retrospective not found'], 404);
        }

        if (!$this->hasTeamAccess($retrospective->getTeam())) {
            return $this->json(['success' => false, 'message' => 'Access denied'], 403);
        }

        // Only facilitators can add items to groups
        if ($retrospective->getFacilitator() !== $this->getUser()) {
            return $this->json(['success' => false, 'message' => 'Only facilitators can add items to groups'], 403);
        }

        // Manual CSRF validation
        $data = json_decode($request->getContent(), true);
        $token = $data['_token'] ?? $request->headers->get('X-CSRF-Token');
        
        if (!$token || !$this->isCsrfTokenValid('retrospective_action', $token)) {
            return $this->json(['success' => false, 'message' => 'Invalid CSRF token'], 403);
        }
        $itemId = $data['itemId'] ?? null;
        $groupId = $data['groupId'] ?? null;

        if (!$itemId || !$groupId) {
            return $this->json(['success' => false, 'message' => 'Item ID and Group ID are required'], 400);
        }

        $item = $this->entityManager->getRepository(RetrospectiveItem::class)->find($itemId);
        if (!$item || $item->getRetrospective() !== $retrospective) {
            return $this->json(['success' => false, 'message' => 'Item not found'], 404);
        }

        $group = $this->entityManager->getRepository(RetrospectiveGroup::class)->find($groupId);
        if (!$group || $group->getRetrospective() !== $retrospective) {
            return $this->json(['success' => false, 'message' => 'Group not found'], 404);
        }

        // Add item to group
        $group->addItem($item);
        $item->setGroup($group);

        $this->entityManager->flush();

        // Broadcast update to all connected clients
        $update = new Update(
            "retrospective/{$id}/review",
            json_encode([
                'type' => 'item_added_to_group',
                'item_id' => $itemId,
                'group_id' => $groupId
            ])
        );
        $this->hub->publish($update);

        return $this->json([
            'success' => true,
            'message' => 'Item added to group successfully'
        ]);
    }

    #[Route('/retrospectives/{id}/reorder-items', name: 'app_retrospectives_reorder_items', methods: ['POST'])]
    public function reorderItems(Request $request, int $id): Response
    {
        $retrospective = $this->entityManager->getRepository(Retrospective::class)->find($id);

        if (!$retrospective) {
            return $this->json(['success' => false, 'message' => 'Retrospective not found'], 404);
        }

        if (!$this->hasTeamAccess($retrospective->getTeam())) {
            return $this->json(['success' => false, 'message' => 'Access denied'], 403);
        }

        // Only facilitators can reorder items
        if ($retrospective->getFacilitator() !== $this->getUser()) {
            return $this->json(['success' => false, 'message' => 'Only facilitators can reorder items'], 403);
        }

        // Manual CSRF validation
        $data = json_decode($request->getContent(), true);
        $token = $data['_token'] ?? $request->headers->get('X-CSRF-Token');
        
        if (!$token || !$this->isCsrfTokenValid('retrospective_action', $token)) {
            return $this->json(['success' => false, 'message' => 'Invalid CSRF token'], 403);
        }
        $category = $data['category'] ?? '';
        $itemIds = $data['itemIds'] ?? [];
        $groupIds = $data['groupIds'] ?? [];
        $orderedElements = $data['orderedElements'] ?? [];

        if (empty($category)) {
            return $this->json(['success' => false, 'message' => 'Category is required'], 400);
        }

        // If orderedElements is provided, use it to maintain relative order between items and groups
        if (!empty($orderedElements)) {
            foreach ($orderedElements as $index => $element) {
                if ($element['type'] === 'item') {
                    $item = $this->entityManager->getRepository(RetrospectiveItem::class)->find($element['id']);
                    if ($item && $item->getRetrospective() === $retrospective && $item->getCategory() === $category) {
                        $item->setPosition($index);
                    }
                } elseif ($element['type'] === 'group') {
                    $group = $this->entityManager->getRepository(RetrospectiveGroup::class)->find($element['id']);
                    if ($group && $group->getRetrospective() === $retrospective && $group->getDisplayCategory() === $category) {
                        $group->setPosition($index);
                    }
                }
            }
        } else {
            // Fallback to old logic if orderedElements not provided (backwards compatibility)
            // Update positions for individual items
            foreach ($itemIds as $index => $itemId) {
                $item = $this->entityManager->getRepository(RetrospectiveItem::class)->find($itemId);
                if ($item && $item->getRetrospective() === $retrospective && $item->getCategory() === $category) {
                    $item->setPosition($index);
                }
            }

            // Update positions for groups
            foreach ($groupIds as $index => $groupId) {
                $group = $this->entityManager->getRepository(RetrospectiveGroup::class)->find($groupId);
                if ($group && $group->getRetrospective() === $retrospective && $group->getDisplayCategory() === $category) {
                    $group->setPosition($index);
                }
            }
        }

        $this->entityManager->flush();

        // Broadcast update to all connected clients
        $update = new Update(
            "retrospective/{$id}/review",
            json_encode([
                'type' => 'items_reordered',
                'category' => $category,
                'item_ids' => $itemIds,
                'group_ids' => $groupIds
            ])
        );
        $this->hub->publish($update);
        
        // Also broadcast to general retrospective topic
        $update2 = new Update(
            "retrospective/{$id}",
            json_encode([
                'type' => 'items_reordered',
                'category' => $category,
                'item_ids' => $itemIds,
                'group_ids' => $groupIds
            ])
        );
        $this->hub->publish($update2);

        return $this->json([
            'success' => true,
            'message' => 'Items reordered successfully'
        ]);
    }

    #[Route('/retrospectives/{id}/timer-status', name: 'app_retrospectives_timer_status', methods: ['GET'])]
    public function timerStatus(int $id): Response
    {
        $retrospective = $this->entityManager->getRepository(Retrospective::class)->find($id);
        
        if (!$retrospective) {
            return $this->json(['success' => false, 'message' => 'Retrospective not found'], 404);
        }

        return $this->json([
            'success' => true,
            'isActive' => $retrospective->isTimerActive(),
            'remainingSeconds' => $retrospective->getTimerRemainingSeconds(),
            'currentStep' => $retrospective->getCurrentStep()
        ]);
    }

    private function hasTeamAccess(Team $team): bool
    {
        return $this->teamService->hasTeamAccess($team, $this->getUser());
    }

    #[Route('/retrospectives/{id}/join', name: 'app_retrospectives_join', methods: ['POST'])]
    public function join(int $id): Response
    {
        // error_log("join() called for retrospective ID: $id");
        
        $retrospective = $this->entityManager->getRepository(Retrospective::class)->find($id);
        
        if (!$retrospective) {
            // error_log("Retrospective not found for ID: $id");
            throw $this->createNotFoundException('Retrospective not found');
        }

        // Check if user has access to the team
        if (!$this->hasTeamAccess($retrospective->getTeam())) {
            // error_log("Access denied for retrospective ID: $id");
            throw $this->createAccessDeniedException('You do not have access to this retrospective');
        }

        $user = $this->getUser();
        // error_log("User joining: " . ($user ? $user->getEmail() : 'null'));
        
        // Store user as connected (in a real app, use Redis or similar)
        $this->connectedUsersService->addUser($id, $user);
        
        // Notify all users via Mercure about the new connection
        $this->connectedUsersService->publishUpdate($id);
        
        // error_log("User added to connected users for retrospective: $id");
        
        return $this->json([
            'success' => true,
            'user' => [
                'id' => $user->getId(),
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
                'email' => $user->getEmail(),
                'avatar' => $user->getAvatar(),
            ]
        ]);
    }

    #[Route('/retrospectives/{id}/leave', name: 'app_retrospectives_leave', methods: ['POST'])]
    public function leave(int $id): Response
    {
        $retrospective = $this->entityManager->getRepository(Retrospective::class)->find($id);
        
        if (!$retrospective) {
            throw $this->createNotFoundException('Retrospective not found');
        }

        $user = $this->getUser();
        
        // Remove user from connected list
        $this->connectedUsersService->removeUser($id, $user);
        
        // Notify all users via Mercure about the user leaving
        $this->connectedUsersService->publishUpdate($id);
        
        return $this->json(['success' => true]);
    }

    #[Route('/retrospectives/{id}/mercure-token', name: 'app_retrospectives_mercure_token', methods: ['GET'])]
    public function getMercureToken(int $id): Response
    {
        $retrospective = $this->entityManager->getRepository(Retrospective::class)->find($id);
        
        if (!$retrospective) {
            return $this->json(['success' => false, 'message' => 'Retrospective not found'], 404);
        }

        if (!$this->hasTeamAccess($retrospective->getTeam())) {
            return $this->json(['success' => false, 'message' => 'Access denied'], 403);
        }

        // Use RetrospectiveService to generate Mercure token
        $token = $this->retrospectiveService->generateMercureToken($id);
        
        return $this->json([
            'success' => true,
            'token' => $token
        ]);
    }

    #[Route('/retrospectives/{id}/debug-connected-users', name: 'app_retrospectives_debug_connected_users', methods: ['GET'])]
    public function debugConnectedUsers(int $id): Response
    {
        $connectedUsers = $this->connectedUsersService->getConnectedUsers($id);
        
        return $this->json([
            'success' => true,
            'debug' => true,
            'users' => $connectedUsers,
            'count' => count($connectedUsers),
            'raw_data' => $connectedUsers
        ]);
    }

    #[Route('/retrospectives/{id}/connected-users', name: 'app_retrospectives_connected_users', methods: ['GET'])]
    public function getConnectedUsersEndpoint(int $id): Response
    {
        // error_log("getConnectedUsersEndpoint() called for retrospective ID: $id");
        
        // Update current user's lastSeen timestamp
        $this->connectedUsersService->updateLastSeen($id, $this->getUser());
        
        $connectedUsers = $this->connectedUsersService->getConnectedUsers($id);
        // error_log("Found " . count($connectedUsers) . " connected users");
        
        // Publish heartbeat update with timer like states
        $this->connectedUsersService->publishUpdate($id);
        
        return $this->json([
            'success' => true,
            'users' => $connectedUsers
        ]);
    }





    #[Route('/retrospectives/{id}/votes', name: 'app_retrospectives_get_votes', methods: ['GET'])]
    public function getVotes(int $id): Response
    {
        $retrospective = $this->entityManager->getRepository(Retrospective::class)->find($id);
        
        if (!$retrospective) {
            return $this->json(['success' => false, 'message' => 'Retrospective not found'], 404);
        }

        $user = $this->getUser();
        if (!$retrospective->getTeam()->hasMember($user)) {
            return $this->json(['success' => false, 'message' => 'You are not a participant'], 403);
        }

        // Use VotingService to get votes for user
        $votesData = $this->votingService->getVotesForUser($retrospective, $user);
        $totalVotes = array_sum(array_column($votesData, 'voteCount'));

        return $this->json([
            'success' => true,
            'votes' => $votesData,
            'totalVotes' => $totalVotes
        ]);
    }

    #[Route('/retrospectives/{id}/vote', name: 'app_retrospectives_vote', methods: ['POST'])]
    public function vote(Request $request, int $id): Response
    {
        $retrospective = $this->entityManager->getRepository(Retrospective::class)->find($id);
        
        if (!$retrospective) {
            return $this->json(['success' => false, 'message' => 'Retrospective not found'], 404);
        }

        // Check if user is a participant
        $user = $this->getUser();
        if (!$retrospective->getTeam()->hasMember($user)) {
            return $this->json(['success' => false, 'message' => 'You are not a participant in this retrospective'], 403);
        }

        $data = json_decode($request->getContent(), true);
        $targetId = $data['targetId'] ?? null;
        $targetType = $data['targetType'] ?? null;
        $voteCount = $data['voteCount'] ?? 0;

        if (!$targetId || !$targetType || !in_array($targetType, ['item', 'group'])) {
            return $this->json(['success' => false, 'message' => 'Invalid vote data'], 400);
        }

        try {
            // Use VotingService to submit vote
            $remainingVotes = $this->votingService->submitVote(
                $retrospective,
                $user,
                $targetType,
                $targetId,
                $voteCount
            );

            return $this->json([
                'success' => true,
                'message' => 'Vote saved successfully',
                'remainingVotes' => $remainingVotes
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => 'Failed to save vote: ' . $e->getMessage()], 500);
        }
    }


    /**
     * Mark an item or group as discussed
     * 
     * @param Request $request
     * @param int $id Retrospective ID
     * @return Response JSON response
     */
    #[Route('/retrospectives/{id}/mark-discussed', name: 'app_retrospectives_mark_discussed', methods: ['POST'])]
    public function markDiscussed(Request $request, int $id): Response
    {
        $retrospective = $this->entityManager->getRepository(Retrospective::class)->find($id);
        
        if (!$retrospective) {
            return $this->json(['success' => false, 'message' => 'Retrospective not found'], 404);
        }
        
        // Check if user has access to this retrospective (facilitator or team member)
        $user = $this->getUser();
        $hasAccess = false;
        
        // Check if user is facilitator
        if ($retrospective->getFacilitator() === $user) {
            $hasAccess = true;
        } else {
            // Check if user is team member
            $team = $retrospective->getTeam();
            foreach ($team->getTeamMembers() as $member) {
                if ($member->getUser() === $user && $member->isActive()) {
                    $hasAccess = true;
                    break;
                }
            }
        }
        
        if (!$hasAccess) {
            return $this->json(['success' => false, 'message' => 'Access denied'], 403);
        }
        
        $data = json_decode($request->getContent(), true);
        $itemId = $data['itemId'] ?? null;
        $itemType = $data['itemType'] ?? null;
        
        if (!$itemId || !$itemType) {
            return $this->json(['success' => false, 'message' => 'Invalid request data'], 400);
        }
        
        try {
            if ($itemType === 'item') {
                $item = $this->entityManager->getRepository(RetrospectiveItem::class)->find($itemId);
                
                if (!$item) {
                    return $this->json(['success' => false, 'message' => 'Item not found'], 404);
                }
                
                // Check if item belongs to this retrospective
                $itemRetrospective = null;
                if ($item->getGroup()) {
                    // Item belongs to a group, check retrospective through group
                    $itemRetrospective = $item->getGroup()->getRetrospective();
                } else {
                    // Item is individual, check retrospective directly
                    $itemRetrospective = $item->getRetrospective();
                }
                
                if (!$itemRetrospective || $itemRetrospective->getId() !== $id) {
                    return $this->json(['success' => false, 'message' => 'Item not in retrospective'], 404);
                }
                
                $item->setIsDiscussed(true);
                $this->entityManager->persist($item);
                
            } elseif ($itemType === 'group') {
                $group = $this->entityManager->getRepository(RetrospectiveGroup::class)->find($itemId);
                
                if (!$group || $group->getRetrospective()->getId() !== $id) {
                    return $this->json(['success' => false, 'message' => 'Group not found'], 404);
                }
                
                $group->setIsDiscussed(true);
                $this->entityManager->persist($group);
                
            } else {
                return $this->json(['success' => false, 'message' => 'Invalid item type'], 400);
            }
            
            $this->entityManager->flush();
            
            // Send real-time update via Mercure
            $update = new Update(
                "retrospectives/{$id}/discussion",
                json_encode([
                    'type' => 'item_discussed',
                    'itemId' => $itemId,
                    'itemType' => $itemType,
                    'memberName' => $user->getFullName(),
                    'timestamp' => time()
                ])
            );
            $this->hub->publish($update);
            
            return $this->json(['success' => true, 'message' => 'Item marked as discussed']);
            
        } catch (\Exception $e) {
            $this->logger->error('Error marking item as discussed: ' . $e->getMessage());
            return $this->json(['success' => false, 'message' => 'Internal server error'], 500);
        }
    }

    #[Route('/retrospectives/{id}/timer-like-update', name: 'app_retrospectives_timer_like_update', methods: ['POST'])]
    public function timerLikeUpdate(int $id, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        error_log("DEBUG: timerLikeUpdate method START - ID: $id");
        try {
            error_log("DEBUG: timerLikeUpdate called for ID: $id");
            
            $retrospective = $this->entityManager->getRepository(Retrospective::class)->find($id);
            
            if (!$retrospective) {
                error_log("DEBUG: Retrospective not found for ID: $id");
                return $this->json(['success' => false, 'message' => 'Retrospective not found'], 404);
            }

            error_log("DEBUG: Retrospective found: " . $retrospective->getId());

            // Check access
            if (!$this->hasTeamAccess($retrospective->getTeam())) {
                error_log("DEBUG: Access denied for team: " . $retrospective->getTeam()->getId());
                return $this->json(['success' => false, 'message' => 'Access denied'], 403);
            }

            error_log("DEBUG: Access granted");

            $user = $this->getUser();
            if (!$user) {
                error_log("DEBUG: User not authenticated");
                return $this->json(['success' => false, 'message' => 'User not authenticated'], 401);
            }

            error_log("DEBUG: User authenticated: " . $user->getId());

            $data = json_decode($request->getContent(), true);
            error_log("DEBUG: Request data: " . json_encode($data));
            
            $isLiked = $data['isLiked'] ?? false;
            $userId = $data['userId'] ?? $user->getId();
            $userName = $data['userName'] ?? $user->getFullName();
            $skipSave = $data['skipSave'] ?? false;

            error_log("DEBUG: Processed data - isLiked: " . ($isLiked ? 'true' : 'false') . ", userId: $userId, userName: $userName, skipSave: " . ($skipSave ? 'true' : 'false'));

            // Save timer like state in database only if not skipping save
            if (!$skipSave) {
                $timerLikeRepo = $this->entityManager->getRepository(\App\Entity\TimerLike::class);
                $existingTimerLike = $timerLikeRepo->findByUserAndRetrospective($user, $retrospective);
                
                if ($existingTimerLike) {
                    $existingTimerLike->setIsLiked($isLiked);
                } else {
                    $timerLike = new \App\Entity\TimerLike();
                    $timerLike->setUser($user);
                    $timerLike->setRetrospective($retrospective);
                    $timerLike->setIsLiked($isLiked);
                    $this->entityManager->persist($timerLike);
                }
                
                $this->entityManager->flush();
                error_log("DEBUG: Timer like state saved in database for user {$userId} in retrospective {$id}");
            } else {
                error_log("DEBUG: Skipping database save for user {$userId} in retrospective {$id} - broadcast only");
            }

            // Send real-time update via Mercure to all users
            $update = new Update(
                "retrospectives/{$id}/discussion",
                json_encode([
                    'type' => 'timer_like_update',
                    'userId' => $userId,
                    'userName' => $userName,
                    'isLiked' => $isLiked,
                    'timestamp' => time()
                ])
            );
            
            error_log("DEBUG: Publishing Mercure update");
            $this->hub->publish($update);
            error_log("DEBUG: Mercure update published successfully");

            return $this->json([
                'success' => true, 
                'message' => 'Timer like update broadcasted',
                'data' => [
                    'userId' => $userId,
                    'userName' => $userName,
                    'isLiked' => $isLiked
                ]
            ]);

        } catch (\Exception $e) {
            error_log("DEBUG: Exception in timerLikeUpdate: " . $e->getMessage());
            error_log("DEBUG: Exception trace: " . $e->getTraceAsString());
            $this->logger->error('Error broadcasting timer like update: ' . $e->getMessage());
            return $this->json(['success' => false, 'message' => 'Internal server error'], 500);
        }
    }

    #[Route('/retrospectives/{id}/timer-like-status', name: 'app_retrospectives_timer_like_status', methods: ['GET'])]
    public function getTimerLikeStatus(int $id, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $retrospective = $this->entityManager->getRepository(Retrospective::class)->find($id);
            
            if (!$retrospective) {
                return $this->json(['success' => false, 'message' => 'Retrospective not found'], 404);
            }

            // Check access
            if (!$this->hasTeamAccess($retrospective->getTeam())) {
                return $this->json(['success' => false, 'message' => 'Access denied'], 403);
            }

            $user = $this->getUser();
            if (!$user) {
                return $this->json(['success' => false, 'message' => 'User not authenticated'], 401);
            }

            // Get timer like state from database
            $timerLikeRepo = $this->entityManager->getRepository(\App\Entity\TimerLike::class);
            $timerLike = $timerLikeRepo->findByUserAndRetrospective($user, $retrospective);

            if ($timerLike) {
                return $this->json([
                    'success' => true,
                    'data' => [
                        'isLiked' => $timerLike->isLiked(),
                        'timestamp' => $timerLike->getUpdatedAt()->getTimestamp(),
                        'userId' => $user->getId(),
                        'retrospectiveId' => $id
                    ]
                ]);
            } else {
                return $this->json([
                    'success' => true,
                    'data' => [
                        'isLiked' => false,
                        'timestamp' => null,
                        'userId' => $user->getId(),
                        'retrospectiveId' => $id
                    ]
                ]);
            }

        } catch (\Exception $e) {
            $this->logger->error('Error getting timer like status: ' . $e->getMessage());
            return $this->json(['success' => false, 'message' => 'Internal server error'], 500);
        }
    }

    #[Route('/retrospectives/{id}/all-timer-like-states', name: 'app_retrospectives_all_timer_like_states', methods: ['GET'])]
    public function getAllTimerLikeStates(int $id, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $retrospective = $this->entityManager->getRepository(Retrospective::class)->find($id);
            
            if (!$retrospective) {
                return $this->json(['success' => false, 'message' => 'Retrospective not found'], 404);
            }

            // Check access
            if (!$this->hasTeamAccess($retrospective->getTeam())) {
                return $this->json(['success' => false, 'message' => 'Access denied'], 403);
            }

            $user = $this->getUser();
            if (!$user) {
                return $this->json(['success' => false, 'message' => 'User not authenticated'], 401);
            }

            // Get all timer like states from database
            $timerLikeRepo = $this->entityManager->getRepository(\App\Entity\TimerLike::class);
            $timerLikes = $timerLikeRepo->findByRetrospective($retrospective);
            
            $allStates = [];
            
            foreach ($timerLikes as $timerLike) {
                $allStates[] = [
                    'userId' => $timerLike->getUser()->getId(),
                    'userName' => $timerLike->getUser()->getFullName(),
                    'isLiked' => $timerLike->isLiked(),
                    'timestamp' => $timerLike->getUpdatedAt()->getTimestamp()
                ];
            }

            return $this->json([
                'success' => true,
                'data' => $allStates
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Error getting all timer like states: ' . $e->getMessage());
            return $this->json(['success' => false, 'message' => 'Internal server error'], 500);
        }
    }


    protected function isCsrfTokenValid(string $id, ?string $token): bool
    {
        // Skip CSRF validation for retrospective actions in development
        // In production, enable proper CSRF validation
        if ($id === 'retrospective_action') {
            return true;
        }
        
        // Use parent implementation for other token IDs
        return parent::isCsrfTokenValid($id, $token);
    }
}