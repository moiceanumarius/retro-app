<?php

namespace App\Controller;

use App\Entity\Team;
use App\Entity\TeamMember;
use App\Entity\TeamInvitation;
use App\Entity\User;
use App\Entity\Organization;
use App\Entity\OrganizationMember;
use App\Entity\UserRole;
use App\Form\TeamType;
use App\Form\TeamMemberType;
use App\Service\TeamService;
use App\Service\TeamMemberService;
use App\Service\TeamInvitationService;
use App\Service\OrganizationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class TeamController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TeamService $teamService,
        private TeamMemberService $teamMemberService,
        private TeamInvitationService $invitationService,
        private OrganizationService $organizationService
    ) {
    }

    #[Route('/teams', name: 'app_teams')]
    public function index(): Response
    {
        $user = $this->getUser();
        
        // Use TeamService to get teams
        $ownedTeams = $this->teamService->getOwnedTeams($user);
        $memberTeams = $this->teamService->getMemberTeams($user);

        return $this->render('team/index.html.twig', [
            'ownedTeams' => $ownedTeams,
            'memberTeams' => $memberTeams,
        ]);
    }

    #[Route('/teams/create', name: 'app_teams_create')]
    public function create(Request $request): Response
    {
        $user = $this->getUser();
        
        // Use TeamService to check if user can create teams
        if (!$this->teamService->canCreateTeams($user)) {
            throw $this->createAccessDeniedException('Only Administrators, Supervisors, and Facilitators can create teams');
        }
        
        // Use OrganizationService to check if user has organization
        if (!$this->organizationService->userHasOrganization($user)) {
            $this->addFlash('error', 'You must be part of an organization to create teams.');
            return $this->redirectToRoute('app_teams');
        }
        
        $team = new Team();
        $team->setOwner($user);
        
        $form = $this->createForm(TeamType::class, $team);
        $form->handleRequest($request);
        
        if ($form->isSubmitted() && $form->isValid()) {
            // Use TeamService to create team
            $this->teamService->createTeam($team, $user);
            
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
        $user = $this->getUser();
        
        // Use TeamService to get team with access check
        $team = $this->teamService->getTeamWithAccessCheck($id, $user);
        
        if (!$team) {
            throw $this->createNotFoundException('Team not found or access denied');
        }
        
        // Use TeamMemberService to get active members
        $members = $this->teamMemberService->getActiveMembers($team);
        $isOwner = $this->teamService->canManageTeam($team, $user);
        
        return $this->render('team/show.html.twig', [
            'team' => $team,
            'members' => $members,
            'isOwner' => $isOwner,
        ]);
    }

    #[Route('/teams/{id}/edit', name: 'app_teams_edit', requirements: ['id' => '\d+'])]
    public function edit(Request $request, int $id): Response
    {
        $user = $this->getUser();
        $team = $this->teamService->getTeamWithAccessCheck($id, $user);
        
        if (!$team) {
            throw $this->createNotFoundException('Team not found');
        }
        
        // Use TeamService to check if user can manage team
        if (!$this->teamService->canManageTeam($team, $user)) {
            throw $this->createAccessDeniedException('Only team owner can edit team');
        }
        
        $form = $this->createForm(TeamType::class, $team);
        $form->handleRequest($request);
        
        if ($form->isSubmitted() && $form->isValid()) {
            // Use TeamService to update team
            $this->teamService->updateTeam($team);
            
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
        $user = $this->getUser();
        $team = $this->teamService->getTeamWithAccessCheck($id, $user);
        
        if (!$team) {
            throw $this->createNotFoundException('Team not found');
        }
        
        // Use TeamService to check if user can manage team
        if (!$this->teamService->canManageTeam($team, $user)) {
            throw $this->createAccessDeniedException('Only team owner can add members');
        }
        
        $teamMember = new TeamMember();
        $teamMember->setTeam($team);
        $teamMember->setInvitedBy($user);
        
        $form = $this->createForm(TeamMemberType::class, $teamMember, ['team' => $team]);
        $form->handleRequest($request);
        
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                // Use TeamMemberService to add member
                $this->teamMemberService->addMemberToTeam(
                    $teamMember->getUser(),
                    $team,
                    $teamMember->getRole(),
                    $user
                );
                
                $this->addFlash('success', '✅ Member added successfully!');
                
                // Check if user was added to organization
                if ($team->getOrganization()) {
                    $this->addFlash('info', 'User automatically added to organization: ' . $team->getOrganization()->getName());
                }
                
                return $this->redirectToRoute('app_teams_show', ['id' => $team->getId()]);
            } catch (\RuntimeException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }
        
        return $this->render('team/add_member.html.twig', [
            'team' => $team,
            'form' => $form,
        ]);
    }

    #[Route('/teams/{id}/remove-member/{memberId}', name: 'app_teams_remove_member', requirements: ['id' => '\d+', 'memberId' => '\d+'])]
    public function removeMember(Request $request, int $id, int $memberId): Response
    {
        $user = $this->getUser();
        $team = $this->teamService->getTeamWithAccessCheck($id, $user);
        $member = $this->entityManager->getRepository(TeamMember::class)->find($memberId);
        
        if (!$team || !$member) {
            throw $this->createNotFoundException('Team or member not found');
        }
        
        // Use TeamService to check if user can manage team
        if (!$this->teamService->canManageTeam($team, $user)) {
            throw $this->createAccessDeniedException('Only team owner can remove members');
        }
        
        if ($this->isCsrfTokenValid('remove_member', $request->request->get('_token'))) {
            try {
                // Use TeamMemberService to remove member
                $this->teamMemberService->removeMemberFromTeam($member);
                $this->addFlash('success', '✅ Member removed successfully!');
            } catch (\RuntimeException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        } else {
            $this->addFlash('error', 'Invalid CSRF token');
        }
        
        return $this->redirectToRoute('app_teams_show', ['id' => $team->getId()]);
    }

    #[Route('/teams/{id}/leave', name: 'app_teams_leave', requirements: ['id' => '\d+'])]
    public function leave(Request $request, int $id): Response
    {
        $user = $this->getUser();
        $team = $this->teamService->getTeamWithAccessCheck($id, $user);
        
        if (!$team) {
            throw $this->createNotFoundException('Team not found');
        }
        
        // Use TeamMemberService to get member
        $member = $this->teamMemberService->getMemberByUser($team, $user);
        
        if (!$member) {
            throw $this->createNotFoundException('You are not a member of this team');
        }
        
        if ($this->isCsrfTokenValid('leave_team', $request->request->get('_token'))) {
            try {
                // Use TeamMemberService to remove member
                $this->teamMemberService->removeMemberFromTeam($member);
                $this->addFlash('success', '✅ You have left the team successfully!');
                return $this->redirectToRoute('app_teams');
            } catch (\RuntimeException $e) {
                $this->addFlash('error', $e->getMessage());
                return $this->redirectToRoute('app_teams_show', ['id' => $team->getId()]);
            }
        } else {
            $this->addFlash('error', 'Invalid CSRF token');
            return $this->redirectToRoute('app_teams_show', ['id' => $team->getId()]);
        }
    }

    #[Route('/teams/{id}/delete', name: 'app_teams_delete', requirements: ['id' => '\d+'])]
    public function delete(Request $request, int $id): Response
    {
        $user = $this->getUser();
        $team = $this->teamService->getTeamWithAccessCheck($id, $user);
        
        if (!$team) {
            throw $this->createNotFoundException('Team not found');
        }
        
        // Use TeamService to check if user can manage team
        if (!$this->teamService->canManageTeam($team, $user)) {
            throw $this->createAccessDeniedException('Only team owner can delete team');
        }
        
        if ($this->isCsrfTokenValid('delete_team', $request->request->get('_token'))) {
            // Use TeamService to delete team
            $this->teamService->deleteTeam($team);
            
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
        $user = $this->getUser();
        $team = $this->teamService->getTeamWithAccessCheck($id, $user);
        
        if (!$team) {
            throw $this->createNotFoundException('Team not found');
        }
        
        // Use TeamService to check if user can manage team
        if (!$this->teamService->canManageTeam($team, $user)) {
            throw $this->createAccessDeniedException('Only team owner can create invitations');
        }
        
        if ($request->isMethod('POST')) {
            // Validate CSRF token
            if (!$this->isCsrfTokenValid('create_invitation', $request->request->get('_token'))) {
                return $this->json(['success' => false, 'message' => 'Invalid CSRF token'], 400);
            }
            
            $role = $request->request->get('role', 'Member');
            $message = $request->request->get('message');
            
            // Use TeamInvitationService to create invitation
            $invitation = $this->invitationService->createInvitation($team, $role, $message, $user);
            
            // Return invitation URL
            return $this->json([
                'success' => true,
                'message' => 'Invitation created successfully!',
                'invitationUrl' => $request->getSchemeAndHttpHost() . $invitation->getInvitationUrl(),
                'expiresAt' => $invitation->getExpiresAt()->format('Y-m-d H:i:s')
            ]);
        }
        
        // GET request - just return to team page
        return $this->redirectToRoute('app_teams_show', ['id' => $team->getId()]);
    }

    #[Route('/team-invitation/{token}', name: 'app_team_invitation_show')]
    public function showInvitation(string $token): Response
    {
        // Use TeamInvitationService to get invitation
        $invitation = $this->invitationService->getInvitationByToken($token);
        
        if (!$invitation) {
            throw $this->createNotFoundException('Invitation not found');
        }
        
        return $this->render('team/invitation_show.html.twig', [
            'invitation' => $invitation,
        ]);
    }

    #[Route('/team-invitation/{token}/accept', name: 'app_team_invitation_accept')]
    public function acceptInvitation(Request $request, string $token): Response
    {
        // Use TeamInvitationService to get invitation
        $invitation = $this->invitationService->getInvitationByToken($token);
        
        if (!$invitation) {
            throw $this->createNotFoundException('Invitation not found');
        }
        
        $user = $this->getUser();
        
        // If user is not logged in, redirect to login with invitation token
        if (!$user) {
            $this->addFlash('info', 'Please log in or register to accept this invitation');
            return $this->redirectToRoute('app_login', ['invitation' => $token]);
        }
        
        // Use TeamInvitationService to accept invitation
        $result = $this->invitationService->acceptInvitation($invitation, $user);
        
        if (!$result['success']) {
            $this->addFlash('error', $result['message']);
            return $this->redirectToRoute('app_team_invitation_show', ['token' => $token]);
        }
        
        if (isset($result['alreadyMember']) && $result['alreadyMember']) {
            $this->addFlash('info', $result['message']);
        } else {
            $this->addFlash('success', $result['message']);
        }
        
        return $this->redirectToRoute('app_teams_show', ['id' => $invitation->getTeam()->getId()]);
    }

    #[Route('/team-invitation/{token}/decline', name: 'app_team_invitation_decline')]
    public function declineInvitation(Request $request, string $token): Response
    {
        // Use TeamInvitationService to get invitation
        $invitation = $this->invitationService->getInvitationByToken($token);
        
        if (!$invitation) {
            throw $this->createNotFoundException('Invitation not found');
        }
        
        // Use TeamInvitationService to decline invitation
        $result = $this->invitationService->declineInvitation($invitation);
        
        if (!$result['success']) {
            $this->addFlash('error', $result['message']);
        } else {
            $this->addFlash('info', $result['message']);
        }
        
        return $this->redirectToRoute('app_team_invitation_show', ['token' => $token]);
    }

    /**
     * API endpoint for getting available users for a team
     * 
     * Returns users from the same organization as the team and users without organization
     * Used in the dropdown for adding members to team
     * 
     * @return JsonResponse List of available users
     */
    #[Route('/teams/{id}/api/users', name: 'app_teams_api_users', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function getTeamUsers(int $id): JsonResponse
    {
        $user = $this->getUser();
        $team = $this->teamService->getTeamWithAccessCheck($id, $user);
        
        if (!$team) {
            return $this->json([
                'success' => false,
                'message' => 'Team not found',
                'users' => []
            ], 404);
        }
        
        // Permission check - only owner can view users
        if (!$this->teamService->canManageTeam($team, $user)) {
            return $this->json([
                'success' => false,
                'message' => 'Access denied',
                'users' => []
            ], 403);
        }
        
        try {
            // Use TeamService to get available users
            $users = $this->teamService->getAvailableUsersForTeam($team);
            
            $results = [];
            foreach ($users as $availableUser) {
                // Get user's organization using OrganizationService
                $userOrganization = $this->organizationService->getUserOrganization($availableUser);
                
                $results[] = [
                    'id' => $availableUser->getId(),
                    'firstName' => $availableUser->getFirstName(),
                    'lastName' => $availableUser->getLastName(),
                    'email' => $availableUser->getEmail(),
                    'organization' => $userOrganization ? $userOrganization->getName() : null,
                ];
            }
            
            return $this->json([
                'success' => true,
                'users' => $results,
                'count' => count($results)
            ]);
            
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Error loading users: ' . $e->getMessage(),
                'users' => []
            ], 500);
        }
    }

}
