<?php

namespace App\Service;

use App\Entity\RetrospectiveAction;
use App\Entity\User;
use App\Repository\RetrospectiveActionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ActionService
 * 
 * Service for managing retrospective actions business logic
 * Handles action CRUD operations, assignments, and status management
 */
class ActionService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RetrospectiveActionRepository $actionRepository
    ) {}

    /**
     * Get actions based on user role and permissions
     */
    public function getActionsForUser(User $user): array
    {
        if ($user->hasRole('ROLE_ADMIN')) {
            // Admin sees all actions
            return $this->actionRepository->findAll();
        } elseif ($user->hasAnyRole(['ROLE_SUPERVISOR', 'ROLE_FACILITATOR'])) {
            // Supervisors and facilitators see actions from their teams
            return $this->actionRepository->findBySupervisorOrFacilitator($user);
        } else {
            // Regular users see actions assigned to them
            return $this->actionRepository->findBy(['assignedTo' => $user]);
        }
    }

    /**
     * Group actions by status
     */
    public function groupActionsByStatus(array $actions): array
    {
        return [
            'pending' => array_filter($actions, fn($action) => $action->getStatus() === 'pending'),
            'in_progress' => array_filter($actions, fn($action) => $action->getStatus() === 'in_progress'),
            'completed' => array_filter($actions, fn($action) => $action->getStatus() === 'completed'),
            'cancelled' => array_filter($actions, fn($action) => $action->getStatus() === 'cancelled'),
        ];
    }

    /**
     * Mark action as reviewed
     */
    public function markAsReviewed(RetrospectiveAction $action): void
    {
        $action->setIsReviewed(true);
        $this->entityManager->flush();
    }

    /**
     * Mark action as not reviewed
     */
    public function markAsNotReviewed(RetrospectiveAction $action): void
    {
        $action->setIsReviewed(false);
        $this->entityManager->flush();
    }

    /**
     * Reset all reviewed actions for a team
     */
    public function resetReviewedActionsForTeam(int $teamId): int
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->update(\App\Entity\RetrospectiveAction::class, 'a')
           ->set('a.isReviewed', ':reviewed')
           ->leftJoin('a.retrospective', 'r')
           ->where('r.team = :teamId')
           ->andWhere('a.isReviewed = :currentReviewed')
           ->setParameter('reviewed', false)
           ->setParameter('teamId', $teamId)
           ->setParameter('currentReviewed', true);

        return $qb->getQuery()->execute();
    }

    /**
     * Assign action to user
     */
    public function assignAction(RetrospectiveAction $action, User $user): void
    {
        $action->setAssignedTo($user);
        $this->entityManager->flush();
    }

    /**
     * Update action status
     */
    public function updateStatus(RetrospectiveAction $action, string $status): void
    {
        $allowedStatuses = ['pending', 'in_progress', 'completed', 'cancelled'];
        
        if (!in_array($status, $allowedStatuses)) {
            throw new \InvalidArgumentException("Invalid status: {$status}");
        }

        $action->setStatus($status);
        
        // If status is completed, set completed date
        if ($status === 'completed' && !$action->getCompletedAt()) {
            $action->setCompletedAt(new \DateTime());
        }
        
        $this->entityManager->flush();
    }

    /**
     * Update action details
     */
    public function updateAction(
        RetrospectiveAction $action, 
        string $description, 
        ?\DateTime $dueDate = null
    ): void {
        $action->setDescription($description);
        
        if ($dueDate !== null) {
            $action->setDueDate($dueDate);
        }
        
        $this->entityManager->flush();
    }

    /**
     * Delete action
     */
    public function deleteAction(RetrospectiveAction $action): void
    {
        $this->entityManager->remove($action);
        $this->entityManager->flush();
    }

    /**
     * Create new action
     */
    public function createAction(
        \App\Entity\Retrospective $retrospective,
        string $description,
        ?\DateTime $dueDate = null,
        ?User $assignedTo = null
    ): RetrospectiveAction {
        $action = new RetrospectiveAction();
        $action->setRetrospective($retrospective);
        $action->setDescription($description);
        $action->setStatus('pending');
        $action->setIsReviewed(false);
        
        if ($dueDate !== null) {
            $action->setDueDate($dueDate);
        }
        
        if ($assignedTo !== null) {
            $action->setAssignedTo($assignedTo);
        }
        
        $this->entityManager->persist($action);
        $this->entityManager->flush();
        
        return $action;
    }

    /**
     * Check if action is overdue
     */
    public function isActionOverdue(RetrospectiveAction $action): bool
    {
        $dueDate = $action->getDueDate();
        
        if ($dueDate === null) {
            return false;
        }
        
        $status = $action->getStatus();
        
        if (in_array($status, ['completed', 'cancelled'])) {
            return false;
        }
        
        return $dueDate < new \DateTime();
    }

    /**
     * Get action by ID with security check
     */
    public function getActionWithAccessCheck(int $actionId, User $user): ?RetrospectiveAction
    {
        $action = $this->actionRepository->find($actionId);
        
        if (!$action) {
            return null;
        }
        
        // Check if user has access to this action
        if (!$this->userHasAccessToAction($action, $user)) {
            return null;
        }
        
        return $action;
    }

    /**
     * Check if user has access to action
     */
    public function userHasAccessToAction(RetrospectiveAction $action, User $user): bool
    {
        // Admin has access to all actions
        if ($user->hasRole('ROLE_ADMIN')) {
            return true;
        }
        
        // Supervisors and facilitators have access to actions from their teams
        if ($user->hasAnyRole(['ROLE_SUPERVISOR', 'ROLE_FACILITATOR'])) {
            $team = $action->getRetrospective()->getTeam();
            
            // Check if user is team owner
            if ($team->getOwner()->getId() === $user->getId()) {
                return true;
            }
            
            // Check if user is team member
            foreach ($team->getTeamMembers() as $teamMember) {
                if ($teamMember->getUser()->getId() === $user->getId()) {
                    return true;
                }
            }
        }
        
        // Regular users have access to actions assigned to them
        if ($action->getAssignedTo() && $action->getAssignedTo()->getId() === $user->getId()) {
            return true;
        }
        
        return false;
    }
}

