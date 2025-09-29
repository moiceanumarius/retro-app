<?php

namespace App\Controller;

use App\Entity\Team;
use App\Entity\TeamMember;
use App\Entity\TeamInvitation;
use App\Entity\User;
use App\Form\TeamType;
use App\Form\TeamMemberType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TeamController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }

    #[Route('/teams', name: 'app_teams')]
    public function index(): Response
    {
        $user = $this->getUser();
        
        // Get teams where user is owner or member
        $ownedTeams = $this->entityManager->getRepository(Team::class)
            ->findBy(['owner' => $user, 'isActive' => true]);
            
        $memberTeams = $this->entityManager->getRepository(TeamMember::class)
            ->createQueryBuilder('tm')
            ->join('tm.team', 't')
            ->where('tm.user = :user')
            ->andWhere('tm.isActive = :active')
            ->andWhere('t.isActive = :active')
            ->andWhere('t.owner != :user')
            ->setParameter('user', $user)
            ->setParameter('active', true)
            ->getQuery()
            ->getResult();

        return $this->render('team/index.html.twig', [
            'ownedTeams' => $ownedTeams,
            'memberTeams' => $memberTeams,
        ]);
    }

    #[Route('/teams/create', name: 'app_teams_create')]
    public function create(Request $request): Response
    {
        $team = new Team();
        $team->setOwner($this->getUser());
        
        $form = $this->createForm(TeamType::class, $team);
        $form->handleRequest($request);
        
        if ($form->isSubmitted() && $form->isValid()) {
            // Add owner as team member
            $ownerMember = new TeamMember();
            $ownerMember->setTeam($team);
            $ownerMember->setUser($this->getUser());
            $ownerMember->setRole('Owner');
            $ownerMember->setInvitedBy($this->getUser());
            
            $this->entityManager->persist($team);
            $this->entityManager->persist($ownerMember);
            $this->entityManager->flush();
            
            $this->addFlash('success', '✅ Team created successfully!');
            
            return $this->redirectToRoute('app_teams_show', ['id' => $team->getId()]);
        }
        
        return $this->render('team/create.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/teams/{id}', name: 'app_teams_show', requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $team = $this->entityManager->getRepository(Team::class)->find($id);
        
        if (!$team || !$team->isActive()) {
            throw $this->createNotFoundException('Team not found');
        }
        
        $user = $this->getUser();
        
        // Check if user has access to this team
        if (!$this->hasTeamAccess($team, $user)) {
            throw $this->createAccessDeniedException('You do not have access to this team');
        }
        
        $members = $team->getActiveMembers();
        $isOwner = $team->getOwner() === $user;
        
        return $this->render('team/show.html.twig', [
            'team' => $team,
            'members' => $members,
            'isOwner' => $isOwner,
        ]);
    }

    #[Route('/teams/{id}/edit', name: 'app_teams_edit', requirements: ['id' => '\d+'])]
    public function edit(Request $request, int $id): Response
    {
        $team = $this->entityManager->getRepository(Team::class)->find($id);
        
        if (!$team || !$team->isActive()) {
            throw $this->createNotFoundException('Team not found');
        }
        
        // Only owner can edit team
        if ($team->getOwner() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Only team owner can edit team');
        }
        
        $form = $this->createForm(TeamType::class, $team);
        $form->handleRequest($request);
        
        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($team);
            $this->entityManager->flush();
            
            $this->addFlash('success', '✅ Team updated successfully!');
            
            return $this->redirectToRoute('app_teams_show', ['id' => $team->getId()]);
        }
        
        return $this->render('team/edit.html.twig', [
            'team' => $team,
            'form' => $form,
        ]);
    }

    #[Route('/teams/{id}/add-member', name: 'app_teams_add_member', requirements: ['id' => '\d+'])]
    public function addMember(Request $request, int $id): Response
    {
        $team = $this->entityManager->getRepository(Team::class)->find($id);
        
        if (!$team || !$team->isActive()) {
            throw $this->createNotFoundException('Team not found');
        }
        
        // Only owner can add members
        if ($team->getOwner() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Only team owner can add members');
        }
        
        $teamMember = new TeamMember();
        $teamMember->setTeam($team);
        $teamMember->setInvitedBy($this->getUser());
        
        $form = $this->createForm(TeamMemberType::class, $teamMember);
        $form->handleRequest($request);
        
        if ($form->isSubmitted() && $form->isValid()) {
            // Check if user is already a member
            if ($team->hasMember($teamMember->getUser())) {
                $this->addFlash('error', 'User is already a member of this team');
                return $this->render('team/add_member.html.twig', [
                    'team' => $team,
                    'form' => $form,
                ]);
            }
            
            $this->entityManager->persist($teamMember);
            $this->entityManager->flush();
            
            $this->addFlash('success', '✅ Member added successfully!');
            
            return $this->redirectToRoute('app_teams_show', ['id' => $team->getId()]);
        }
        
        return $this->render('team/add_member.html.twig', [
            'team' => $team,
            'form' => $form,
        ]);
    }

    #[Route('/teams/{id}/remove-member/{memberId}', name: 'app_teams_remove_member', requirements: ['id' => '\d+', 'memberId' => '\d+'])]
    public function removeMember(Request $request, int $id, int $memberId): Response
    {
        $team = $this->entityManager->getRepository(Team::class)->find($id);
        $member = $this->entityManager->getRepository(TeamMember::class)->find($memberId);
        
        if (!$team || !$team->isActive() || !$member) {
            throw $this->createNotFoundException('Team or member not found');
        }
        
        // Only owner can remove members
        if ($team->getOwner() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Only team owner can remove members');
        }
        
        // Cannot remove owner
        if ($member->isOwner()) {
            $this->addFlash('error', 'Cannot remove team owner');
            return $this->redirectToRoute('app_teams_show', ['id' => $team->getId()]);
        }
        
        if ($this->isCsrfTokenValid('remove_member', $request->request->get('_token'))) {
            $member->setIsActive(false);
            $this->entityManager->persist($member);
            $this->entityManager->flush();
            
            $this->addFlash('success', '✅ Member removed successfully!');
        } else {
            $this->addFlash('error', 'Invalid CSRF token');
        }
        
        return $this->redirectToRoute('app_teams_show', ['id' => $team->getId()]);
    }

    #[Route('/teams/{id}/leave', name: 'app_teams_leave', requirements: ['id' => '\d+'])]
    public function leave(Request $request, int $id): Response
    {
        $team = $this->entityManager->getRepository(Team::class)->find($id);
        
        if (!$team || !$team->isActive()) {
            throw $this->createNotFoundException('Team not found');
        }
        
        $user = $this->getUser();
        $member = $team->getMemberByUser($user);
        
        if (!$member) {
            throw $this->createNotFoundException('You are not a member of this team');
        }
        
        // Owner cannot leave team
        if ($member->isOwner()) {
            $this->addFlash('error', 'Team owner cannot leave the team. Transfer ownership first.');
            return $this->redirectToRoute('app_teams_show', ['id' => $team->getId()]);
        }
        
        if ($this->isCsrfTokenValid('leave_team', $request->request->get('_token'))) {
            $member->setIsActive(false);
            $this->entityManager->persist($member);
            $this->entityManager->flush();
            
            $this->addFlash('success', '✅ You have left the team successfully!');
            
            return $this->redirectToRoute('app_teams');
        } else {
            $this->addFlash('error', 'Invalid CSRF token');
            return $this->redirectToRoute('app_teams_show', ['id' => $team->getId()]);
        }
    }

    #[Route('/teams/{id}/delete', name: 'app_teams_delete', requirements: ['id' => '\d+'])]
    public function delete(Request $request, int $id): Response
    {
        $team = $this->entityManager->getRepository(Team::class)->find($id);
        
        if (!$team || !$team->isActive()) {
            throw $this->createNotFoundException('Team not found');
        }
        
        // Only owner can delete team
        if ($team->getOwner() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Only team owner can delete team');
        }
        
        if ($this->isCsrfTokenValid('delete_team', $request->request->get('_token'))) {
            $team->setIsActive(false);
            $this->entityManager->persist($team);
            $this->entityManager->flush();
            
            $this->addFlash('success', '✅ Team deleted successfully!');
            
            return $this->redirectToRoute('app_teams');
        } else {
            $this->addFlash('error', 'Invalid CSRF token');
            return $this->redirectToRoute('app_teams_show', ['id' => $team->getId()]);
        }
    }

    #[Route('/teams/{id}/create-invitation', name: 'app_teams_create_invitation', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function createInvitation(Request $request, int $id): Response
    {
        $team = $this->entityManager->getRepository(Team::class)->find($id);
        
        if (!$team || !$team->isActive()) {
            throw $this->createNotFoundException('Team not found');
        }
        
        // Only owner can create invitations
        if ($team->getOwner() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Only team owner can create invitations');
        }
        
        if ($request->isMethod('POST')) {
            // Validate CSRF token
            if (!$this->isCsrfTokenValid('create_invitation', $request->request->get('_token'))) {
                if ($request->isXmlHttpRequest()) {
                    return $this->json(['success' => false, 'message' => 'Invalid CSRF token'], 400);
                }
                $this->addFlash('error', 'Invalid CSRF token');
                return $this->redirectToRoute('app_teams_show', ['id' => $team->getId()]);
            }
            
            $email = $request->request->get('email');
            $role = $request->request->get('role', 'Member');
            $message = $request->request->get('message');
            
            if (!$email) {
                $this->addFlash('error', 'Email is required');
                return $this->redirectToRoute('app_teams_show', ['id' => $team->getId()]);
            }
            
            // Check if user is already a member
            $existingUser = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($existingUser && $team->hasMember($existingUser)) {
                $this->addFlash('error', 'User is already a member of this team');
                return $this->redirectToRoute('app_teams_show', ['id' => $team->getId()]);
            }
            
            // Check if there's already a pending invitation
            $existingInvitation = $this->entityManager->getRepository(TeamInvitation::class)
                ->findOneBy(['email' => $email, 'team' => $team, 'status' => 'pending']);
            
            if ($existingInvitation && !$existingInvitation->isExpired()) {
                $this->addFlash('error', 'There is already a pending invitation for this email');
                return $this->redirectToRoute('app_teams_show', ['id' => $team->getId()]);
            }
            
            // Create new invitation
            $invitation = new TeamInvitation();
            $invitation->setTeam($team);
            $invitation->setEmail($email);
            $invitation->setRole($role);
            $invitation->setMessage($message);
            $invitation->setInvitedBy($this->getUser());
            
            $this->entityManager->persist($invitation);
            $this->entityManager->flush();
            
            // Return JSON response for AJAX
            if ($request->isXmlHttpRequest()) {
                return $this->json([
                    'success' => true,
                    'message' => 'Invitation created successfully!',
                    'invitationUrl' => $request->getSchemeAndHttpHost() . $invitation->getInvitationUrl()
                ]);
            }
            
            $this->addFlash('success', '✅ Invitation created successfully! Share this link: ' . $request->getSchemeAndHttpHost() . $invitation->getInvitationUrl());
            
            return $this->redirectToRoute('app_teams_show', ['id' => $team->getId()]);
        }
        
        return $this->render('team/create_invitation.html.twig', [
            'team' => $team,
        ]);
    }

    #[Route('/team-invitation/{token}', name: 'app_team_invitation_show')]
    public function showInvitation(string $token): Response
    {
        $invitation = $this->entityManager->getRepository(TeamInvitation::class)
            ->findOneBy(['token' => $token]);
        
        if (!$invitation) {
            throw $this->createNotFoundException('Invitation not found');
        }
        
        if ($invitation->isExpired()) {
            $invitation->setStatus('expired');
            $this->entityManager->persist($invitation);
            $this->entityManager->flush();
        }
        
        return $this->render('team/invitation_show.html.twig', [
            'invitation' => $invitation,
        ]);
    }

    #[Route('/team-invitation/{token}/accept', name: 'app_team_invitation_accept')]
    public function acceptInvitation(Request $request, string $token): Response
    {
        $invitation = $this->entityManager->getRepository(TeamInvitation::class)
            ->findOneBy(['token' => $token]);
        
        if (!$invitation) {
            throw $this->createNotFoundException('Invitation not found');
        }
        
        if ($invitation->isExpired()) {
            $this->addFlash('error', 'This invitation has expired');
            return $this->redirectToRoute('app_team_invitation_show', ['token' => $token]);
        }
        
        if ($invitation->isAccepted()) {
            $this->addFlash('info', 'This invitation has already been accepted');
            return $this->redirectToRoute('app_team_invitation_show', ['token' => $token]);
        }
        
        $user = $this->getUser();
        
        // If user is not logged in, redirect to login with invitation token
        if (!$user) {
            $this->addFlash('info', 'Please log in or register to accept this invitation');
            return $this->redirectToRoute('app_login', ['invitation' => $token]);
        }
        
        // Check if user email matches invitation email
        if ($user->getEmail() !== $invitation->getEmail()) {
            $this->addFlash('error', 'This invitation is for a different email address');
            return $this->redirectToRoute('app_team_invitation_show', ['token' => $token]);
        }
        
        // Check if user is already a member
        if ($invitation->getTeam()->hasMember($user)) {
            $this->addFlash('info', 'You are already a member of this team');
            $invitation->setStatus('accepted');
            $invitation->setAcceptedAt(new \DateTimeImmutable());
            $invitation->setAcceptedBy($user);
            $this->entityManager->persist($invitation);
            $this->entityManager->flush();
            return $this->redirectToRoute('app_teams_show', ['id' => $invitation->getTeam()->getId()]);
        }
        
        // Add user to team
        $teamMember = new TeamMember();
        $teamMember->setTeam($invitation->getTeam());
        $teamMember->setUser($user);
        $teamMember->setRole($invitation->getRole() ?: 'Member');
        $teamMember->setInvitedBy($invitation->getInvitedBy());
        
        $invitation->setStatus('accepted');
        $invitation->setAcceptedAt(new \DateTimeImmutable());
        $invitation->setAcceptedBy($user);
        
        $this->entityManager->persist($teamMember);
        $this->entityManager->persist($invitation);
        $this->entityManager->flush();
        
        $this->addFlash('success', '✅ You have successfully joined the team!');
        
        return $this->redirectToRoute('app_teams_show', ['id' => $invitation->getTeam()->getId()]);
    }

    #[Route('/team-invitation/{token}/decline', name: 'app_team_invitation_decline')]
    public function declineInvitation(Request $request, string $token): Response
    {
        $invitation = $this->entityManager->getRepository(TeamInvitation::class)
            ->findOneBy(['token' => $token]);
        
        if (!$invitation) {
            throw $this->createNotFoundException('Invitation not found');
        }
        
        if ($invitation->isExpired()) {
            $this->addFlash('error', 'This invitation has expired');
            return $this->redirectToRoute('app_team_invitation_show', ['token' => $token]);
        }
        
        if ($invitation->isAccepted() || $invitation->isDeclined()) {
            $this->addFlash('info', 'This invitation has already been processed');
            return $this->redirectToRoute('app_team_invitation_show', ['token' => $token]);
        }
        
        $invitation->setStatus('declined');
        $this->entityManager->persist($invitation);
        $this->entityManager->flush();
        
        $this->addFlash('info', 'You have declined the team invitation');
        
        return $this->redirectToRoute('app_team_invitation_show', ['token' => $token]);
    }

    /**
     * Check if user has access to team
     */
    private function hasTeamAccess(Team $team, User $user): bool
    {
        // Owner has access
        if ($team->getOwner() === $user) {
            return true;
        }
        
        // Active members have access
        return $team->hasMember($user);
    }
}
