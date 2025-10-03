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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
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
        $user = $this->getUser();
        
        // Only Administrator, Supervisor, and Facilitator can create teams
        if (!$user->hasRole('ROLE_ADMIN') && !$user->hasRole('ROLE_SUPERVISOR') && !$user->hasRole('ROLE_FACILITATOR')) {
            throw $this->createAccessDeniedException('Only Administrators, Supervisors, and Facilitators can create teams');
        }
        
        $team = new Team();
        $team->setOwner($user);
        
        $form = $this->createForm(TeamType::class, $team);
        $form->handleRequest($request);
        
        if ($form->isSubmitted() && $form->isValid()) {
            // Add owner as team member
            $ownerMember = new TeamMember();
            $ownerMember->setTeam($team);
            $ownerMember->setUser($this->getUser());
            $ownerMember->setRole('Owner');
            $ownerMember->setInvitedBy($this->getUser());
            
            // Set organization for team (if owner is member of an organization)
            $ownerOrganizationMemberships = $this->entityManager
                ->getRepository(OrganizationMember::class)
                ->createQueryBuilder('om')
                ->where('om.user = :user')
                ->andWhere('om.isActive = :active')
                ->andWhere('om.leftAt IS NULL')
                ->setParameter('user', $this->getUser())
                ->setParameter('active', true)
                ->getQuery()
                ->getResult();
            
            if (!empty($ownerOrganizationMemberships)) {
                $ownerOrganizationMembership = $ownerOrganizationMemberships[0]; // Take first active membership
                if ($ownerOrganizationMembership->getOrganization()) {
                    $team->setOrganization($ownerOrganizationMembership->getOrganization());
                }
            }
            
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
        $isOwner = $team->getOwner()->getId() === $user->getId();
        
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
        
        $form = $this->createForm(TeamMemberType::class, $teamMember, ['team' => $team]);
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
            
            $userToInvite = $teamMember->getUser();
            $selectedRole = $teamMember->getRole();
            
            // Handle "Current Role" option - don't modify user's role
            if ($selectedRole === 'CURRENT_ROLE') {
                // Get user's current role from their global roles
                $userRoleRepository = $this->entityManager->getRepository(UserRole::class);
                $userRole = $userRoleRepository->findOneBy([
                    'user' => $userToInvite,
                    'isActive' => true
                ]);
                
                if ($userRole) {
                    $teamMember->setRole($userRole->getRole()->getName());
                } else {
                    // Fallback to Member if no role found
                    $teamMember->setRole('Member');
                }
            }
            
            // Automatically add user to organization when added to team
            $teamOrganization = $team->getOrganization();
            
            if ($teamOrganization) {
                // Check if user is already a member of the organization
                $organizationMemberRepository = $this->entityManager->getRepository(OrganizationMember::class);
                $existingOrgMember = $organizationMemberRepository->findOneBy([
                    'user' => $userToInvite,
                    'organization' => $teamOrganization
                ]);
                
                if ($existingOrgMember) {
                    // If user was a member before
                    if (!$existingOrgMember->isActive() || $existingOrgMember->getLeftAt() !== null) {
                        // Reactivateformer member
                        $existingOrgMember->setIsActive(true);
                        $existingOrgMember->setLeftAt(null);
                        $existingOrgMember->setJoinedAt(new \DateTimeImmutable());
                        $existingOrgMember->setInvitedBy($this->getUser());
                        $this->entityManager->persist($existingOrgMember);
                        $this->addFlash('info', 'User automatically readded to organization: ' . $teamOrganization->getName());
                    }
                    // If already active member, do nothing
                } else {
                    // Add new user to organization
                    $orgMember = new OrganizationMember();
                    $orgMember->setUser($userToInvite);
                    $orgMember->setOrganization($teamOrganization);
                    $orgMember->setRole('Member');
                    $orgMember->setInvitedBy($this->getUser());
                    $orgMember->setJoinedAt(new \DateTimeImmutable());
                    $orgMember->setIsActive(true);
                    $this->entityManager->persist($orgMember);
                    $this->addFlash('info', 'User automatically added to organization: ' . $teamOrganization->getName());
                }
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
        // Debug: Log that we reached the controller
        error_log("createInvitation called for team ID: $id, method: " . $request->getMethod());
        
        $team = $this->entityManager->getRepository(Team::class)->find($id);
        
        if (!$team || !$team->isActive()) {
            throw $this->createNotFoundException('Team not found');
        }
        
        // Only owner can create invitations
        if ($team->getOwner() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Only team owner can create invitations');
        }
        
        if ($request->isMethod('POST')) {
            // Debug CSRF token
            $token = $request->request->get('_token');
            $isValid = $this->isCsrfTokenValid('create_invitation', $token);
            error_log("CSRF Token: $token, Valid: " . ($isValid ? 'YES' : 'NO'));
            
            // Validate CSRF token
            if (!$isValid) {
                return $this->json(['success' => false, 'message' => 'Invalid CSRF token'], 400);
            }
            
            $role = $request->request->get('role', 'Member');
            $message = $request->request->get('message');
            
            // Check if there's already a pending generic invitation for this team
            $existingInvitation = $this->entityManager->getRepository(TeamInvitation::class)
                ->findOneBy(['email' => 'team@invitation.com', 'team' => $team, 'status' => 'pending']);
            
            if ($existingInvitation && !$existingInvitation->isExpired()) {
                // Return existing invitation link
                return $this->json([
                    'success' => true,
                    'invitationUrl' => $request->getSchemeAndHttpHost() . $existingInvitation->getInvitationUrl(),
                    'expiresAt' => $existingInvitation->getExpiresAt()->format('Y-m-d H:i:s')
                ]);
            }
            
            // Create new invitation
            $invitation = new TeamInvitation();
            $invitation->setTeam($team);
            $invitation->setEmail('team@invitation.com'); // Generic email for team invitations
            $invitation->setRole($role);
            $invitation->setMessage($message);
            $invitation->setInvitedBy($this->getUser());
            
            $this->entityManager->persist($invitation);
            $this->entityManager->flush();
            
            // Always return JSON for POST requests (popup expects JSON)
            return $this->json([
                'success' => true,
                'message' => 'Invitation created successfully!',
                'invitationUrl' => $request->getSchemeAndHttpHost() . $invitation->getInvitationUrl()
            ]);
        }
        
        // GET request - just return to team page (popup handles invitation creation)
        return $this->redirectToRoute('app_teams_show', ['id' => $team->getId()]);
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
        
        // Skip email validation for generic team invitations
        // Generic invitations (team@invitation.com) can be used by anyone
        
        // Check if user has already used this invitation
        if ($invitation->hasBeenUsedBy($user)) {
            $this->addFlash('error', 'You have already used this invitation. Each invitation can only be used once per user.');
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
        
        // Mark that this user has used this invitation
        $invitation->addUsedBy($user);
        
        $this->entityManager->persist($teamMember);
        $this->entityManager->persist($invitation);
        
        // Automatically add user to organization when accepting team invitation
        $teamOrganization = $invitation->getTeam()->getOrganization();
        
        if ($teamOrganization) {
            // Check if user is already a member of the organization
            $organizationMemberRepository = $this->entityManager->getRepository(OrganizationMember::class);
            $existingOrgMember = $organizationMemberRepository->findOneBy([
                'user' => $user,
                'organization' => $teamOrganization
            ]);
            
            if ($existingOrgMember) {
                // If user was a member before (active or inactive)
                if ($existingOrgMember->isActive() && $existingOrgMember->getLeftAt() === null) {
                    // User is already an active member, do nothing
                } else {
                    // Reactivate former member
                    $existingOrgMember->setIsActive(true);
                    $existingOrgMember->setLeftAt(null);
                    $existingOrgMember->setRole('MEMBER');
                    $existingOrgMember->setJoinedAt(new \DateTimeImmutable()); // Update join date
                    $existingOrgMember->setInvitedBy($invitation->getInvitedBy());
                    
                    $this->entityManager->persist($existingOrgMember);
                }
            } else {
                // Create new organization member (first time)
                $organizationMember = new OrganizationMember();
                $organizationMember->setOrganization($teamOrganization);
                $organizationMember->setUser($user);
                $organizationMember->setRole('MEMBER');
                $organizationMember->setInvitedBy($invitation->getInvitedBy());
                $organizationMember->setJoinedAt(new \DateTimeImmutable());
                $organizationMember->setIsActive(true);
                
                $this->entityManager->persist($organizationMember);
            }
        }
        
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
     * API endpoint pentru obținerea utilizatorilor disponibili pentru o echipă
     * 
     * Returnează utilizatorii din aceeași organizație cu echipa și utilizatorii fără organizație
     * Utilizat în dropdown-ul de adăugare membri la echipă
     * 
     * @return JsonResponse Lista utilizatorilor disponibili
     */
    #[Route('/teams/{id}/api/users', name: 'app_teams_api_users', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function getTeamUsers(int $id): JsonResponse
    {
        $team = $this->entityManager->getRepository(Team::class)->find($id);
        
        if (!$team || !$team->isActive()) {
            return $this->json([
                'success' => false,
                'message' => 'Team not found',
                'users' => []
            ], 404);
        }
        
        // Verificarea permisirii - doar owner poate vedea utilizatorii
        if ($team->getOwner()->getId() !== $this->getUser()->getId()) {
            return $this->json([
                'success' => false,
                'message' => 'Access denied',
                'users' => []
            ], 403);
        }
        
        try {
            $userRepository = $this->entityManager->getRepository(User::class);
            $qb = $userRepository->createQueryBuilder('u')
                ->leftJoin('u.organizationMemberships', 'om')
                ->leftJoin('om.organization', 'o')
                ->orderBy('u.firstName', 'ASC');
            
            if ($team->getOrganization()) {
                // Show users from the same organization as the team AND users without any organization
                $qb->where('o.id = :teamOrgId OR o.id IS NULL')
                   ->setParameter('teamOrgId', $team->getOrganization()->getId());
            } else {
                // If team has no organization, show only users without organization
                $qb->where('o.id IS NULL');
            }
            
            $users = $qb->getQuery()->getResult();
            
            $results = [];
            foreach ($users as $user) {
                // Get user's organization
                $userOrganization = null;
                foreach ($user->getOrganizationMemberships() as $membership) {
                    if ($membership->isActive() && $membership->getLeftAt() === null) {
                        $userOrganization = $membership->getOrganization()->getName();
                        break;
                    }
                }
                
                $results[] = [
                    'id' => $user->getId(),
                    'firstName' => $user->getFirstName(),
                    'lastName' => $user->getLastName(),
                    'email' => $user->getEmail(),
                    'organization' => $userOrganization,
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

    /**
     * Check if user has access to team
     */
    private function hasTeamAccess(Team $team, User $user): bool
    {
        // Owner has access
        if ($team->getOwner()->getId() === $user->getId()) {
            return true;
        }
        
        // Active members have access
        return $team->hasMember($user);
    }
}
