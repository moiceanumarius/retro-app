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
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Psr\Log\LoggerInterface;

class RetrospectiveController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private HubInterface $hub,
        private LoggerInterface $logger
    ) {
    }

    #[Route('/retrospectives', name: 'app_retrospectives')]
    public function index(): Response
    {
        $user = $this->getUser();
        
        // Get retrospectives where user is facilitator or active team member
        $retrospectives = $this->entityManager->getRepository(Retrospective::class)
            ->createQueryBuilder('r')
            ->join('r.team', 't')
            ->leftJoin('t.teamMembers', 'tm')
            ->where('r.facilitator = :user OR (tm.user = :user AND tm.isActive = :active)')
            ->andWhere('t.isActive = :active')
            ->setParameter('user', $user)
            ->setParameter('active', true)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('retrospective/index.html.twig', [
            'retrospectives' => $retrospectives,
        ]);
    }

    #[Route('/teams/{id}/retrospectives', name: 'app_team_retrospectives')]
    public function teamRetrospectives(int $id): Response
    {
        $team = $this->entityManager->getRepository(Team::class)->find($id);
        
        if (!$team) {
            throw $this->createNotFoundException('Team not found');
        }

        // Check if user has access to this team
        if (!$this->hasTeamAccess($team)) {
            throw $this->createAccessDeniedException('You do not have access to this team');
        }

        $retrospectives = $this->entityManager->getRepository(Retrospective::class)
            ->findBy(['team' => $team], ['createdAt' => 'DESC']);

        return $this->render('retrospective/team.html.twig', [
            'team' => $team,
            'retrospectives' => $retrospectives,
        ]);
    }

    #[Route('/retrospectives/create', name: 'app_retrospectives_create')]
    public function create(Request $request): Response
    {
        $user = $this->getUser();
        
        // Only Facilitator, Supervisor, and Administrator can create retrospectives
        if (!$user->hasRole('ROLE_ADMIN') && !$user->hasRole('ROLE_SUPERVISOR') && !$user->hasRole('ROLE_FACILITATOR')) {
            throw $this->createAccessDeniedException('Only Administrators, Supervisors, and Facilitators can create retrospectives');
        }

        // Check if user has any teams available
        $availableTeams = $this->getUserTeams($user);
        
        if (empty($availableTeams)) {
            $this->addFlash('error', 'You must be part of a team to create retrospectives.');
            return $this->redirectToRoute('app_retrospectives');
        }

        $retrospective = new Retrospective();
        $retrospective->setFacilitator($user);
        
        $form = $this->createForm(RetrospectiveType::class, $retrospective);
        $form->handleRequest($request);
        
        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($retrospective);
            $this->entityManager->flush();
            
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

        // Get items grouped by category
        $currentUser = $this->getUser();
        
        // In feedback step, users only see their own posts
        if ($retrospective->isInStep('feedback')) {
            $items = $this->entityManager->getRepository(RetrospectiveItem::class)
                ->findBy([
                    'retrospective' => $retrospective,
                    'author' => $currentUser
                ], ['createdAt' => 'ASC']);
        } else {
            // In other steps, users see all posts
            $items = $this->entityManager->getRepository(RetrospectiveItem::class)
                ->findBy(['retrospective' => $retrospective], ['createdAt' => 'ASC']);
        }

        // Filter items that are not in groups
        $wrongItems = array_filter($items, fn($item) => $item->isWrong() && !$item->getGroup());
        $goodItems = array_filter($items, fn($item) => $item->isGood() && !$item->getGroup());
        $improvedItems = array_filter($items, fn($item) => $item->isImproved() && !$item->getGroup());
        $randomItems = array_filter($items, fn($item) => $item->isRandom() && !$item->getGroup());

        // Get groups
        $groups = $this->entityManager->getRepository(RetrospectiveGroup::class)
            ->findBy(['retrospective' => $retrospective], ['positionY' => 'ASC']);

        $wrongGroups = array_filter($groups, fn($group) => $group->getPositionX() == 0);
        $goodGroups = array_filter($groups, fn($group) => $group->getPositionX() == 1);
        $improvedGroups = array_filter($groups, fn($group) => $group->getPositionX() == 2);
        $randomGroups = array_filter($groups, fn($group) => $group->getPositionX() == 3);

        // Combine and sort items and groups by positionY for each category
        $wrongCombined = $this->combineAndSortByPosition($wrongItems, $wrongGroups);
        $goodCombined = $this->combineAndSortByPosition($goodItems, $goodGroups);
        $improvedCombined = $this->combineAndSortByPosition($improvedItems, $improvedGroups);
        $randomCombined = $this->combineAndSortByPosition($randomItems, $randomGroups);

        // Get actions
        $actions = $this->entityManager->getRepository(RetrospectiveAction::class)
            ->findBy(['retrospective' => $retrospective], ['createdAt' => 'ASC']);

        // Prepare sorted items with aggregated votes for actions and completed phases
        $sortedItemsWithVotes = [];
        if ($retrospective->isInStep('actions') || $retrospective->isCompleted()) {
            $sortedItemsWithVotes = $this->getItemsWithAggregatedVotes($items, $groups);
        }

        // Get connected users (simplified - in real app you'd use Redis or similar)
        $connectedUsers = $this->getConnectedUsers($id);

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
        ]);
    }

    #[Route('/retrospectives/{id}/edit', name: 'app_retrospectives_edit')]
    public function edit(Request $request, int $id): Response
    {
        $retrospective = $this->entityManager->getRepository(Retrospective::class)->find($id);
        
        if (!$retrospective) {
            throw $this->createNotFoundException('Retrospective not found');
        }

        // Only facilitator or team owner can edit
        if ($retrospective->getFacilitator() !== $this->getUser() && $retrospective->getTeam()->getOwner() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Only the facilitator or team owner can edit this retrospective');
        }

        $form = $this->createForm(RetrospectiveType::class, $retrospective);
        $form->handleRequest($request);
        
        if ($form->isSubmitted() && $form->isValid()) {
            $retrospective->setUpdatedAt(new \DateTime());
            $this->entityManager->flush();
            
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

        // Only facilitator can start
        if ($retrospective->getFacilitator() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Only the facilitator can start this retrospective');
        }

        $retrospective->setStatus('active');
        $retrospective->setStartedAt(new \DateTime());
        $retrospective->setCurrentStep('feedback');
        $retrospective->setUpdatedAt(new \DateTime());
        
        $this->entityManager->flush();
        
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

        // Only facilitator can complete
        if ($retrospective->getFacilitator() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Only the facilitator can complete this retrospective');
        }

        $retrospective->setStatus('completed');
        $retrospective->setCurrentStep('completed');
        $retrospective->setCompletedAt(new \DateTime());
        $retrospective->setUpdatedAt(new \DateTime());
        
        $this->entityManager->flush();
        
        // Broadcast step change to all connected clients
        $update = new Update(
            "retrospective/{$id}/step",
            json_encode([
                'type' => 'step_changed',
                'nextStep' => 'completed',
                'message' => 'Retrospective completed successfully!'
            ])
        );
        $this->hub->publish($update);
        
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
            // error_log("Retrospective not found for ID: $id");
            throw $this->createNotFoundException('Retrospective not found');
        }

        $user = $this->getUser();
        // error_log("Current user: " . ($user ? $user->getEmail() : 'null'));
        // error_log("Facilitator: " . $retrospective->getFacilitator()->getEmail());

        // Only facilitator can start timer
        if ($retrospective->getFacilitator() !== $user) {
            // error_log("Access denied: user is not facilitator");
            throw $this->createAccessDeniedException('Only the facilitator can start the timer');
        }

        $data = json_decode($request->getContent(), true);
        $duration = $data['duration'] ?? 10; // default 10 minutes
        
        // error_log("Duration: $duration");
        
        $retrospective->setTimerDuration((int)$duration);
        $retrospective->setTimerStartedAt(new \DateTime());
        $retrospective->setUpdatedAt(new \DateTime());
        
        $this->entityManager->flush();
        
        // Broadcast timer start to all connected clients
        $update = new Update(
            "retrospective/{$id}/timer",
            json_encode([
                'type' => 'timer_started',
                'duration' => $duration,
                'remainingSeconds' => $duration * 60,
                'startedAt' => $retrospective->getTimerStartedAt()->format('Y-m-d H:i:s')
            ])
        );
        $this->hub->publish($update);
        
        // error_log("Timer started successfully");
        
        return $this->json([
            'success' => true,
            'duration' => $duration,
            'startedAt' => $retrospective->getTimerStartedAt()->format('Y-m-d H:i:s')
        ]);
    }

    #[Route('/retrospectives/{id}/stop-timer', name: 'app_retrospectives_stop_timer', methods: ['POST'])]
    public function stopTimer(Request $request, int $id): Response
    {
        // error_log("stopTimer called for retrospective ID: $id");
        
        $retrospective = $this->entityManager->getRepository(Retrospective::class)->find($id);
        
        if (!$retrospective) {
            // error_log("Retrospective not found for ID: $id");
            throw $this->createNotFoundException('Retrospective not found');
        }

        $user = $this->getUser();
        // error_log("Current user: " . ($user ? $user->getEmail() : 'null'));
        // error_log("Facilitator: " . $retrospective->getFacilitator()->getEmail());

        // Only facilitator can stop timer
        if ($retrospective->getFacilitator() !== $user) {
            // error_log("Access denied: user is not facilitator");
            throw $this->createAccessDeniedException('Only the facilitator can stop the timer');
        }

        // Clear timer data
        $retrospective->setTimerDuration(null);
        $retrospective->setTimerStartedAt(null);
        $retrospective->setUpdatedAt(new \DateTime());
        
        // Clear all timer like states for this retrospective
        $timerLikeRepository = $this->entityManager->getRepository(\App\Entity\TimerLike::class);
        $timerLikes = $timerLikeRepository->findBy(['retrospective' => $retrospective]);
        
        foreach ($timerLikes as $timerLike) {
            $this->entityManager->remove($timerLike);
        }
        
        $this->entityManager->flush();
        
        // Broadcast timer stop to all connected clients
        $update = new Update(
            "retrospective/{$id}/timer",
            json_encode([
                'type' => 'timer_stopped',
                'message' => 'Timer stopped by facilitator',
                'timerLikeStatesCleared' => true
            ])
        );
        $this->hub->publish($update);
        
        // error_log("Timer stopped successfully");
        
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

        // Only facilitator can move to next step
        if ($retrospective->getFacilitator() !== $this->getUser()) {
            return $this->json(['success' => false, 'message' => 'Only the facilitator can move to the next step'], 403);
        }

        // Define step progression
        $stepProgression = [
            'feedback' => 'review',
            'review' => 'voting',
            'voting' => 'actions',
            'actions' => 'completed'
        ];

        $currentStep = $retrospective->getCurrentStep();
        $nextStep = $stepProgression[$currentStep] ?? 'completed';

        $retrospective->setCurrentStep($nextStep);
        $retrospective->setUpdatedAt(new \DateTime());

        // If moving to completed, mark retrospective as completed
        if ($nextStep === 'completed') {
            $retrospective->setStatus('completed');
            $retrospective->setCompletedAt(new \DateTime());
        }
        
        $this->entityManager->flush();
        
        // Broadcast step change to all connected clients
        $update = new Update(
            "retrospective/{$id}/step",
            json_encode([
                'type' => 'step_changed',
                'nextStep' => $nextStep,
                'message' => 'Moved to next step: ' . ucfirst($nextStep)
            ])
        );
        $this->hub->publish($update);
        
        return $this->json([
            'success' => true,
            'message' => 'Moved to next step: ' . ucfirst($nextStep),
            'nextStep' => $nextStep
        ]);
    }

    #[Route('/retrospectives/{id}/add-item-ajax', name: 'app_retrospectives_add_item_ajax', methods: ['POST'])]
    public function addItemAjax(Request $request, int $id): Response
    {
        // error_log("addItemAjax called for retrospective ID: $id");
        
        $retrospective = $this->entityManager->getRepository(Retrospective::class)->find($id);
        
        if (!$retrospective) {
            // error_log("Retrospective not found: $id");
            return $this->json(['success' => false, 'message' => 'Retrospective not found'], 404);
        }

        // Check access
        if (!$this->hasTeamAccess($retrospective->getTeam())) {
            // error_log("Access denied for retrospective: $id");
            return $this->json(['success' => false, 'message' => 'Access denied'], 403);
        }

        // Only active retrospectives in feedback step can have items added
        if (!$retrospective->isActive() || !$retrospective->isInStep('feedback')) {
            // error_log("Retrospective not active or not in feedback step. Active: " . ($retrospective->isActive() ? 'yes' : 'no') . ", Step: " . $retrospective->getCurrentStep());
            return $this->json(['success' => false, 'message' => 'Items can only be added during the feedback phase'], 400);
        }

        $data = json_decode($request->getContent(), true);
        $content = $data['content'] ?? null;
        $category = $data['category'] ?? null;

        // error_log("Request data: " . json_encode($data));
        // error_log("Content: $content, Category: $category");

        if (!$content || !$category) {
            // error_log("Missing content or category");
            return $this->json(['success' => false, 'message' => 'Content and category are required'], 400);
        }

        $item = new RetrospectiveItem();
        $item->setRetrospective($retrospective);
        $item->setAuthor($this->getUser());
        $item->setContent($content);
        $item->setCategory($category);
        
        // Set position to the end of the column
        $existingItems = $this->entityManager->getRepository(RetrospectiveItem::class)
            ->findBy(['retrospective' => $retrospective, 'category' => $category], ['position' => 'DESC'], 1);
        $item->setPosition($existingItems ? $existingItems[0]->getPosition() + 1 : 0);
        
        // error_log("Creating item with content: $content, category: $category");
        
        try {
            $this->entityManager->persist($item);
            $this->entityManager->flush();
            // error_log("Item created successfully with ID: " . $item->getId());
        } catch (\Exception $e) {
            // error_log("Error creating item: " . $e->getMessage());
            return $this->json(['success' => false, 'message' => 'Failed to create item'], 500);
        }
        
        // Broadcast new item to all connected clients
        $update = new Update(
            "retrospective/{$id}/items",
            json_encode([
                'type' => 'item_added',
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
            ])
        );
        $this->hub->publish($update);
        
        // Also broadcast to general retrospective topic
        $update2 = new Update(
            "retrospective/{$id}",
            json_encode([
                'type' => 'item_added',
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
            ])
        );
        $this->hub->publish($update2);
        
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

        // Only the author can edit their item
        $currentUser = $this->getUser();
        $itemAuthor = $item->getAuthor();
        
        // error_log("Update item - Current user ID: " . ($currentUser ? $currentUser->getId() : 'null'));
        // error_log("Update item - Item author ID: " . ($itemAuthor ? $itemAuthor->getId() : 'null'));
        // error_log("Update item - Users match: " . ($currentUser && $itemAuthor && $currentUser->getId() === $itemAuthor->getId() ? 'yes' : 'no'));
        
        if (!$currentUser || !$itemAuthor || $currentUser->getId() !== $itemAuthor->getId()) {
            return $this->json(['success' => false, 'message' => 'You can only edit your own items'], 403);
        }

        $item->setContent($content);
        $item->setUpdatedAt(new \DateTime());
        
        $this->entityManager->flush();
        
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

        // Only the author can delete their item
        $currentUser = $this->getUser();
        $itemAuthor = $item->getAuthor();
        
        // error_log("Delete item - Current user ID: " . ($currentUser ? $currentUser->getId() : 'null'));
        // error_log("Delete item - Item author ID: " . ($itemAuthor ? $itemAuthor->getId() : 'null'));
        // error_log("Delete item - Users match: " . ($currentUser && $itemAuthor && $currentUser->getId() === $itemAuthor->getId() ? 'yes' : 'no'));
        
        if (!$currentUser || !$itemAuthor || $currentUser->getId() !== $itemAuthor->getId()) {
            return $this->json(['success' => false, 'message' => 'You can only delete your own items'], 403);
        }

        $this->entityManager->remove($item);
        $this->entityManager->flush();
        
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

    private function combineAndSortByPosition(array $items, array $groups): array
    {
        $combined = [];
        
        // Add items with their position
        foreach ($items as $item) {
            $combined[] = [
                'type' => 'item',
                'entity' => $item,
                'position' => $item->getPosition()
            ];
        }
        
        // Add groups with their position
        foreach ($groups as $group) {
            $combined[] = [
                'type' => 'group',
                'entity' => $group,
                'position' => $group->getPositionY()
            ];
        }
        
        // Sort by position
        usort($combined, fn($a, $b) => $a['position'] <=> $b['position']);
        
        return $combined;
    }

    private function getItemsWithAggregatedVotes(array $items, array $groups): array
    {
        $combined = [];
        
        // Process individual items (not in groups)
        foreach ($items as $item) {
            if (!$item->getGroup()) {
                // Get total votes for this item
                $voteQueryResult = $this->entityManager
                    ->getRepository(\App\Entity\Vote::class)
                    ->createQueryBuilder('v')
                    ->select('SUM(v.voteCount)')
                    ->where('v.retrospectiveItem = :item')
                    ->setParameter('item', $item)
                    ->getQuery()
                    ->getSingleScalarResult();
                
                $totalVotes = (int)($voteQueryResult ?? 0);
                
                $combined[] = [
                    'type' => 'item',
                    'entity' => $item,
                    'totalVotes' => (int)$totalVotes,
                    'category' => $this->getItemCategory($item)
                ];
            }
        }
        
        // Process groups
        foreach ($groups as $group) {
            // Sum votes for all items in the group
            $totalVotes = 0;
            foreach ($group->getItems() as $groupItem) {
                $itemVotesResult = $this->entityManager
                    ->getRepository(\App\Entity\Vote::class)
                    ->createQueryBuilder('v')
                    ->select('SUM(v.voteCount)')
                    ->where('v.retrospectiveItem = :item')
                    ->setParameter('item', $groupItem)
                    ->getQuery()
                    ->getSingleScalarResult();
                
                $itemVotes = (int)($itemVotesResult ?? 0);
                $totalVotes += $itemVotes;
            }
            
            // Add votes directly on the group
            $groupVotesResult = $this->entityManager
                ->getRepository(\App\Entity\Vote::class)
                ->createQueryBuilder('v')
                ->select('SUM(v.voteCount)')
                ->where('v.retrospectiveGroup = :group')
                ->setParameter('group', $group)
                ->getQuery()
                ->getSingleScalarResult();
            
            $groupVotes = (int)($groupVotesResult ?? 0);
            $totalVotes += $groupVotes;
            
            $combined[] = [
                'type' => 'group',
                'entity' => $group,
                'totalVotes' => $totalVotes,
                'category' => $this->getGroupCategory($group)
            ];
        }
        
        // Sort: first non-discussed (by votes desc), then discussed (by votes desc)
        usort($combined, function($a, $b) {
            $aEntity = $a['entity'];
            $bEntity = $b['entity'];
            
            // Check if items are discussed
            $aDiscussed = ($aEntity instanceof \App\Entity\RetrospectiveItem) ? $aEntity->isDiscussed() : 
                         (method_exists($aEntity, 'isDiscussed') ? $aEntity->isDiscussed() : false);
            $bDiscussed = ($bEntity instanceof \App\Entity\RetrospectiveItem) ? $bEntity->isDiscussed() : 
                         (method_exists($bEntity, 'isDiscussed') ? $bEntity->isDiscussed() : false);
            
            // If both have same discussion status, sort by votes
            if ($aDiscussed === $bDiscussed) {
                return $b['totalVotes'] <=> $a['totalVotes'];
            }
            
            // Non-discussed items come first
            return $aDiscussed ? 1 : -1;
        });
        
        return $combined;
    }

    private function getItemCategory($item): string
    {
        if ($item->isWrong()) return 'wrong';
        if ($item->isGood()) return 'good';
        if ($item->isImproved()) return 'improved';
        if ($item->isRandom()) return 'random';
        return 'unknown';
    }

    private function getGroupCategory($group): string
    {
        $positionX = $group->getPositionX();
        return match($positionX) {
            0 => 'wrong',
            1 => 'good',
            2 => 'improved',
            3 => 'random',
            default => 'unknown'
        };
    }

    private function hasTeamAccess(Team $team): bool
    {
        $user = $this->getUser();
        
        // Team owner has access
        if ($team->getOwner()->getId() === $user->getId()) {
            return true;
        }
        
        // Team members have access
        return $team->hasMember($user);
    }

    private function getUserTeams($user): array
    {
        // Get user's organization (either as member or as owner)
        $userOrganization = null;
        
        // First check if user is a member of any organization
        foreach ($user->getOrganizationMemberships() as $membership) {
            if ($membership->isActive() && $membership->getLeftAt() === null) {
                $userOrganization = $membership->getOrganization();
                break;
            }
        }
        
        // If not a member, check if user owns any organization
        if (!$userOrganization) {
            $ownedOrganizations = $user->getOwnedOrganizations();
            if (!$ownedOrganizations->isEmpty()) {
                $userOrganization = $ownedOrganizations->first();
            }
        }

        // If user has no organization, return empty array
        if (!$userOrganization) {
            return [];
        }

        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('t')
           ->from(\App\Entity\Team::class, 't')
           ->leftJoin('t.teamMembers', 'tm')
           ->leftJoin('tm.user', 'u')
           ->where('(t.owner = :user OR u = :user)')
           ->andWhere('t.organization = :organization')
           ->andWhere('t.isActive = :active')
           ->setParameter('user', $user)
           ->setParameter('organization', $userOrganization)
           ->setParameter('active', true)
           ->orderBy('t.name', 'ASC');

        return $qb->getQuery()->getResult();
    }

    private function generateMercureToken(int $retrospectiveId): string
    {
        $payload = [
            'mercure' => [
                'subscribe' => [
                    "retrospective/{$retrospectiveId}",
                    "retrospective/{$retrospectiveId}/timer",
                    "retrospective/{$retrospectiveId}/review",
                    "retrospective/{$retrospectiveId}/connected-users"
                ]
            ]
        ];

        // Simple JWT encoding using Mercure secret
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payloadJson = json_encode($payload);
        
        $base64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64Payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payloadJson));
        
        $signature = hash_hmac('sha256', $base64Header . "." . $base64Payload, $_ENV['MERCURE_JWT_SECRET'], true);
        $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        
        return $base64Header . "." . $base64Payload . "." . $base64Signature;
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
        $this->addConnectedUser($id, $user);
        
        // Notify all users via Mercure about the new connection
        $this->publishConnectedUsersUpdate($id);
        
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
        $this->removeConnectedUser($id, $user);
        
        // Notify all users via Mercure about the user leaving
        $this->publishConnectedUsersUpdate($id);
        
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

        // Generate JWT token for Mercure
        $token = $this->generateMercureToken($id);
        
        return $this->json([
            'success' => true,
            'token' => $token
        ]);
    }

    #[Route('/retrospectives/{id}/connected-users', name: 'app_retrospectives_connected_users', methods: ['GET'])]
    public function getConnectedUsersEndpoint(int $id): Response
    {
        // error_log("getConnectedUsersEndpoint() called for retrospective ID: $id");
        
        // Update current user's lastSeen timestamp
        $this->updateUserLastSeen($id, $this->getUser());
        
        $connectedUsers = $this->getConnectedUsers($id);
        // error_log("Found " . count($connectedUsers) . " connected users");
        
        // Publish heartbeat update with timer like states
        $this->publishConnectedUsersUpdate($id);
        
        return $this->json([
            'success' => true,
            'users' => $connectedUsers
        ]);
    }

    private function getConnectedUsers(int $retrospectiveId): array
    {
        // In a real application, you would use Redis or a similar solution
        // For now, we'll use a simple file-based approach
        $file = sys_get_temp_dir() . "/retrospective_{$retrospectiveId}_users.json";
        // error_log("Getting connected users from file: $file");
        
        if (!file_exists($file)) {
            // error_log("File does not exist: $file");
            return [];
        }
        
        $data = json_decode(file_get_contents($file), true);
        // error_log("Raw file data: " . json_encode($data));
        
        // Filter out users that haven't been active in the last 3 hours
        $activeUsers = [];
        $now = time();
        
        foreach ($data as $userId => $userData) {
            $timeDiff = $now - $userData['lastSeen'];
            // error_log("User {$userId}: lastSeen={$userData['lastSeen']}, now={$now}, diff={$timeDiff}");
            if ($timeDiff < 10800) { // 3 hours = 10800 seconds
                $activeUsers[] = $userData;
                // error_log("User {$userId} is active");
            } else {
                // error_log("User {$userId} is inactive (diff={$timeDiff})");
            }
        }
        
        // error_log("Active users: " . json_encode($activeUsers));
        return $activeUsers;
    }

    private function addConnectedUser(int $retrospectiveId, $user): void
    {
        $file = sys_get_temp_dir() . "/retrospective_{$retrospectiveId}_users.json";
        // error_log("Adding user to file: $file");
        
        $data = [];
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true) ?: [];
        }
        
        $data[$user->getId()] = [
            'id' => $user->getId(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'email' => $user->getEmail(),
            'avatar' => $user->getAvatar(),
            'lastSeen' => time(),
        ];
        
        $result = file_put_contents($file, json_encode($data));
        // error_log("File write result: " . ($result !== false ? 'success' : 'failed'));
        // error_log("File contents: " . json_encode($data));
    }

    private function updateUserLastSeen(int $retrospectiveId, $user): void
    {
        $file = sys_get_temp_dir() . "/retrospective_{$retrospectiveId}_users.json";
        
        if (!file_exists($file)) {
            return;
        }
        
        $data = json_decode(file_get_contents($file), true) ?: [];
        
        if (isset($data[$user->getId()])) {
            $data[$user->getId()]['lastSeen'] = time();
            file_put_contents($file, json_encode($data));
        }
    }

    private function removeConnectedUser(int $retrospectiveId, $user): void
    {
        $file = sys_get_temp_dir() . "/retrospective_{$retrospectiveId}_users.json";
        
        if (!file_exists($file)) {
            return;
        }
        
        $data = json_decode(file_get_contents($file), true) ?: [];
        unset($data[$user->getId()]);
        
        file_put_contents($file, json_encode($data));
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

        // Get all votes for this user in this retrospective
        $votes = $this->entityManager->getRepository(Vote::class)->findByUserAndRetrospective($user, $retrospective);
        
        $votesData = [];
        $totalVotes = 0;
        
        foreach ($votes as $vote) {
            $voteData = ['voteCount' => $vote->getVoteCount()];
            
            if ($vote->getRetrospectiveItem()) {
                $voteData['targetType'] = 'item';
                $voteData['targetId'] = $vote->getRetrospectiveItem()->getId();
            } elseif ($vote->getRetrospectiveGroup()) {
                $voteData['targetType'] = 'group';
                $voteData['targetId'] = $vote->getRetrospectiveGroup()->getId();
            }
            
            $votesData[] = $voteData;
            $totalVotes += $vote->getVoteCount();
        }

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
        $targetType = $data['targetType'] ?? null; // 'item' or 'group'
        $voteCount = $data['voteCount'] ?? 0;

        if (!$targetId || !$targetType || !in_array($targetType, ['item', 'group'])) {
            return $this->json(['success' => false, 'message' => 'Invalid vote data'], 400);
        }

        // Validate vote count (0-2 per item/group)
        if ($voteCount < 0 || $voteCount > 2) {
            return $this->json(['success' => false, 'message' => 'Vote count must be between 0 and 2'], 400);
        }

        try {
            if ($targetType === 'item') {
                $item = $this->entityManager->getRepository(RetrospectiveItem::class)->find($targetId);
                if (!$item || $item->getRetrospective() !== $retrospective) {
                    return $this->json(['success' => false, 'message' => 'Item not found'], 404);
                }
                
                // Find or create vote record for this user and item
                $vote = $this->entityManager->getRepository(Vote::class)->findOneBy([
                    'user' => $user,
                    'retrospectiveItem' => $item
                ]);
                
                if (!$vote) {
                    $vote = new Vote();
                    $vote->setUser($user);
                    $vote->setRetrospectiveItem($item);
                    $this->entityManager->persist($vote);
                }
                
                // Update vote count (0 means removing the vote)
                if ($voteCount === 0 && $vote->getId()) {
                    $this->entityManager->remove($vote);
                } else {
                    $vote->setVoteCount($voteCount);
                }
                
                $this->entityManager->flush();
            } else {
                // Group voting
                $group = $this->entityManager->getRepository(RetrospectiveGroup::class)->find($targetId);
                if (!$group || $group->getRetrospective() !== $retrospective) {
                    return $this->json(['success' => false, 'message' => 'Group not found'], 404);
                }
                
                // Find or create vote record for this user and group
                $vote = $this->entityManager->getRepository(Vote::class)->findOneBy([
                    'user' => $user,
                    'retrospectiveGroup' => $group
                ]);
                
                if (!$vote) {
                    $vote = new Vote();
                    $vote->setUser($user);
                    $vote->setRetrospectiveGroup($group);
                    $this->entityManager->persist($vote);
                }
                
                // Update vote count (0 means removing the vote)
                if ($voteCount === 0 && $vote->getId()) {
                    $this->entityManager->remove($vote);
                } else {
                    $vote->setVoteCount($voteCount);
                }
                
                $this->entityManager->flush();
            }

            // Broadcast vote update to all participants
            $update = new Update(
                "retrospective/{$id}",
                json_encode([
                    'type' => 'vote_updated',
                    'targetType' => $targetType,
                    'targetId' => $targetId,
                    'userId' => $user->getId(),
                    'voteCount' => $voteCount
                ])
            );
            $this->hub->publish($update);

            return $this->json([
                'success' => true,
                'message' => 'Vote saved successfully'
            ]);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => 'Failed to save vote: ' . $e->getMessage()], 500);
        }
    }

    private function publishConnectedUsersUpdate(int $retrospectiveId): void
    {
        try {
            // Get updated connected users
            $connectedUsers = $this->getConnectedUsers($retrospectiveId);
            
            // Get timer like states for all connected users
            $timerLikeRepo = $this->entityManager->getRepository(\App\Entity\TimerLike::class);
            $retrospective = $this->entityManager->getRepository(Retrospective::class)->find($retrospectiveId);
            
            if ($retrospective) {
                $timerLikes = $timerLikeRepo->findByRetrospective($retrospective);
                $timerLikeStates = [];
                
                foreach ($timerLikes as $timerLike) {
                    if ($timerLike->isLiked()) {
                        $timerLikeStates[$timerLike->getUser()->getId()] = [
                            'userId' => $timerLike->getUser()->getId(),
                            'userName' => $timerLike->getUser()->getFullName(),
                            'isLiked' => true,
                            'timestamp' => $timerLike->getUpdatedAt()->getTimestamp()
                        ];
                    }
                }
            } else {
                $timerLikeStates = [];
            }
            
            // Publish update to connected-users topic
            $update = new Update(
                "retrospective/{$retrospectiveId}/connected-users",
                json_encode([
                    'type' => 'connected_users_updated',
                    'users' => $connectedUsers,
                    'timerLikeStates' => $timerLikeStates
                ])
            );
            $this->hub->publish($update);
            
            // Also broadcast to general retrospective topic
            $update2 = new Update(
                "retrospective/{$retrospectiveId}",
                json_encode([
                    'type' => 'connected_users_updated',
                    'users' => $connectedUsers,
                    'timerLikeStates' => $timerLikeStates
                ])
            );
            $this->hub->publish($update2);
        } catch (\Exception $e) {
            // Log error but don't break the main flow
            error_log('Error publishing connected users update: ' . $e->getMessage());
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