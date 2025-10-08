<?php

namespace App\Service;

use App\Entity\Team;
use App\Entity\TeamInvitation;
use App\Entity\TeamMember;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * TeamInvitationService
 * 
 * Service for managing team invitations
 * Handles invitation creation, acceptance, and validation
 */
class TeamInvitationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TeamMemberService $teamMemberService,
        private OrganizationMemberService $organizationMemberService
    ) {}

    /**
     * Create team invitation
     */
    public function createInvitation(
        Team $team,
        string $role,
        ?string $message,
        User $invitedBy
    ): TeamInvitation {
        // Check if there's already a pending generic invitation for this team
        $existingInvitation = $this->entityManager->getRepository(TeamInvitation::class)
            ->findOneBy(['email' => 'team@invitation.com', 'team' => $team, 'status' => 'pending']);

        if ($existingInvitation && !$existingInvitation->isExpired()) {
            return $existingInvitation;
        }

        // Create new invitation
        $invitation = new TeamInvitation();
        $invitation->setTeam($team);
        $invitation->setEmail('team@invitation.com'); // Generic email for team invitations
        $invitation->setRole($role);
        $invitation->setMessage($message);
        $invitation->setInvitedBy($invitedBy);

        $this->entityManager->persist($invitation);
        $this->entityManager->flush();

        return $invitation;
    }

    /**
     * Accept invitation
     */
    public function acceptInvitation(TeamInvitation $invitation, User $user): array
    {
        // Validation checks
        if ($invitation->isExpired()) {
            return ['success' => false, 'message' => 'This invitation has expired'];
        }

        if ($invitation->isAccepted()) {
            return ['success' => false, 'message' => 'This invitation has already been accepted'];
        }

        // Check if user has already used this invitation
        if ($invitation->hasBeenUsedBy($user)) {
            return ['success' => false, 'message' => 'You have already used this invitation. Each invitation can only be used once per user.'];
        }

        // Check if user is already a member
        if ($invitation->getTeam()->hasMember($user)) {
            $this->markInvitationAsAccepted($invitation, $user);
            return ['success' => true, 'message' => 'You are already a member of this team', 'alreadyMember' => true];
        }

        // Add user to team
        $teamMember = new TeamMember();
        $teamMember->setTeam($invitation->getTeam());
        $teamMember->setUser($user);
        $teamMember->setRole($invitation->getRole() ?: 'Member');
        $teamMember->setInvitedBy($invitation->getInvitedBy());

        // Mark invitation as accepted
        $this->markInvitationAsAccepted($invitation, $user);

        // Mark that this user has used this invitation
        $invitation->addUsedBy($user);

        $this->entityManager->persist($teamMember);
        $this->entityManager->persist($invitation);

        // Automatically add user to organization when accepting team invitation
        $this->ensureUserInOrganization($user, $invitation);

        $this->entityManager->flush();

        return ['success' => true, 'message' => 'You have successfully joined the team!'];
    }

    /**
     * Mark invitation as accepted
     */
    private function markInvitationAsAccepted(TeamInvitation $invitation, User $user): void
    {
        $invitation->setStatus('accepted');
        $invitation->setAcceptedAt(new \DateTimeImmutable());
        $invitation->setAcceptedBy($user);
    }

    /**
     * Ensure user is in organization when accepting invitation
     */
    private function ensureUserInOrganization(User $user, TeamInvitation $invitation): void
    {
        $teamOrganization = $invitation->getTeam()->getOrganization();

        if (!$teamOrganization) {
            return;
        }

        // Check if user is already a member of the organization
        if ($this->organizationMemberService->isUserMemberOfOrganization($user, $teamOrganization)) {
            return;
        }

        // Add user to organization
        $this->organizationMemberService->addMemberToOrganization(
            $user,
            $teamOrganization,
            'MEMBER',
            $invitation->getInvitedBy()
        );
    }

    /**
     * Decline invitation
     */
    public function declineInvitation(TeamInvitation $invitation): array
    {
        if ($invitation->isExpired()) {
            return ['success' => false, 'message' => 'This invitation has expired'];
        }

        if ($invitation->isAccepted() || $invitation->isDeclined()) {
            return ['success' => false, 'message' => 'This invitation has already been processed'];
        }

        $invitation->setStatus('declined');
        $this->entityManager->persist($invitation);
        $this->entityManager->flush();

        return ['success' => true, 'message' => 'You have declined the team invitation'];
    }

    /**
     * Get invitation by token
     */
    public function getInvitationByToken(string $token): ?TeamInvitation
    {
        $invitation = $this->entityManager->getRepository(TeamInvitation::class)
            ->findOneBy(['token' => $token]);

        if ($invitation && $invitation->isExpired() && $invitation->getStatus() === 'pending') {
            $invitation->setStatus('expired');
            $this->entityManager->persist($invitation);
            $this->entityManager->flush();
        }

        return $invitation;
    }
}

