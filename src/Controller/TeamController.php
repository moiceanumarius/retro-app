<?php

namespace App\Controller;

use App\Entity\Team;
use App\Entity\TeamMember;
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
