<?php

namespace App\Service;

use App\Entity\Retrospective;
use App\Entity\User;
use App\Entity\Team;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * RetrospectiveService
 * 
 * Service for managing retrospectives
 * Handles retrospective lifecycle, step progression, and timer management
 */
class RetrospectiveService
{
    private const STEP_PROGRESSION = [
        'feedback' => 'review',
        'review' => 'voting',
        'voting' => 'actions',
        'actions' => 'completed'
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private HubInterface $hub
    ) {}

    /**
     * Get retrospectives for user
     */
    public function getRetrospectivesForUser(User $user): array
    {
        return $this->entityManager->getRepository(Retrospective::class)
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
    }

    /**
     * Get retrospectives for team
     */
    public function getRetrospectivesForTeam(Team $team): array
    {
        return $this->entityManager->getRepository(Retrospective::class)
            ->findBy(['team' => $team], ['createdAt' => 'DESC']);
    }

    /**
     * Create retrospective
     */
    public function createRetrospective(Retrospective $retrospective): void
    {
        $this->entityManager->persist($retrospective);
        $this->entityManager->flush();
    }

    /**
     * Update retrospective
     */
    public function updateRetrospective(Retrospective $retrospective): void
    {
        $retrospective->setUpdatedAt(new \DateTime());
        $this->entityManager->flush();
    }

    /**
     * Start retrospective
     */
    public function startRetrospective(Retrospective $retrospective): void
    {
        $retrospective->setStatus('active');
        $retrospective->setStartedAt(new \DateTime());
        $retrospective->setCurrentStep('feedback');
        $retrospective->setUpdatedAt(new \DateTime());
        
        $this->entityManager->flush();
    }

    /**
     * Complete retrospective
     */
    public function completeRetrospective(Retrospective $retrospective): void
    {
        $retrospective->setStatus('completed');
        $retrospective->setCurrentStep('completed');
        $retrospective->setCompletedAt(new \DateTime());
        $retrospective->setUpdatedAt(new \DateTime());
        
        $this->entityManager->flush();
        
        // Broadcast step change
        $this->broadcastStepChange($retrospective->getId(), 'completed');
    }

    /**
     * Move to next step
     */
    public function moveToNextStep(Retrospective $retrospective): string
    {
        $currentStep = $retrospective->getCurrentStep();
        $nextStep = self::STEP_PROGRESSION[$currentStep] ?? 'completed';

        $retrospective->setCurrentStep($nextStep);
        $retrospective->setUpdatedAt(new \DateTime());

        // If moving to completed, mark retrospective as completed
        if ($nextStep === 'completed') {
            $retrospective->setStatus('completed');
            $retrospective->setCompletedAt(new \DateTime());
        }
        
        $this->entityManager->flush();
        
        // Broadcast step change
        $this->broadcastStepChange($retrospective->getId(), $nextStep);
        
        return $nextStep;
    }

    /**
     * Start timer
     */
    public function startTimer(Retrospective $retrospective, int $duration): void
    {
        $retrospective->setTimerDuration($duration);
        $retrospective->setTimerStartedAt(new \DateTime());
        $retrospective->setUpdatedAt(new \DateTime());
        
        $this->entityManager->flush();
        
        // Broadcast timer start
        $update = new Update(
            "retrospective/{$retrospective->getId()}/timer",
            json_encode([
                'type' => 'timer_started',
                'duration' => $duration,
                'remainingSeconds' => $duration * 60,
                'startedAt' => $retrospective->getTimerStartedAt()->format('Y-m-d H:i:s')
            ])
        );
        $this->hub->publish($update);
    }

    /**
     * Stop timer
     */
    public function stopTimer(Retrospective $retrospective): void
    {
        // Clear timer data
        $retrospective->setTimerDuration(null);
        $retrospective->setTimerStartedAt(null);
        $retrospective->setUpdatedAt(new \DateTime());
        
        // Clear all timer like states for this retrospective
        $timerLikes = $this->entityManager->getRepository(\App\Entity\TimerLike::class)
            ->findBy(['retrospective' => $retrospective]);
        
        foreach ($timerLikes as $timerLike) {
            $this->entityManager->remove($timerLike);
        }
        
        $this->entityManager->flush();
        
        // Broadcast timer stop
        $update = new Update(
            "retrospective/{$retrospective->getId()}/timer",
            json_encode([
                'type' => 'timer_stopped',
                'message' => 'Timer stopped by facilitator',
                'timerLikeStatesCleared' => true
            ])
        );
        $this->hub->publish($update);
    }

    /**
     * Broadcast step change to all connected clients
     */
    private function broadcastStepChange(int $retrospectiveId, string $nextStep): void
    {
        $update = new Update(
            "retrospective/{$retrospectiveId}/step",
            json_encode([
                'type' => 'step_changed',
                'nextStep' => $nextStep,
                'message' => 'Moved to next step: ' . ucfirst($nextStep)
            ])
        );
        $this->hub->publish($update);
    }

    /**
     * Check if user can create retrospectives
     */
    public function canCreateRetrospectives(User $user): bool
    {
        return $user->hasAnyRole(['ROLE_ADMIN', 'ROLE_SUPERVISOR', 'ROLE_FACILITATOR']);
    }

    /**
     * Check if user can manage retrospective (facilitator or team owner)
     */
    public function canManageRetrospective(Retrospective $retrospective, User $user): bool
    {
        return $retrospective->getFacilitator()->getId() === $user->getId() ||
               $retrospective->getTeam()->getOwner()->getId() === $user->getId();
    }

    /**
     * Check if user is facilitator
     */
    public function isFacilitator(Retrospective $retrospective, User $user): bool
    {
        return $retrospective->getFacilitator()->getId() === $user->getId();
    }

    /**
     * Generate Mercure token for retrospective
     */
    public function generateMercureToken(int $retrospectiveId): string
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

        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payloadJson = json_encode($payload);
        
        $base64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64Payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payloadJson));
        
        $signature = hash_hmac('sha256', $base64Header . "." . $base64Payload, $_ENV['MERCURE_JWT_SECRET'], true);
        $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        
        return $base64Header . "." . $base64Payload . "." . $base64Signature;
    }
}

