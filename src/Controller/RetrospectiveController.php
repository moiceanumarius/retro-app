<?php

namespace App\Controller;

use App\Entity\Retrospective;
use App\Entity\RetrospectiveItem;
use App\Entity\RetrospectiveAction;
use App\Entity\Team;
use App\Form\RetrospectiveType;
use App\Form\RetrospectiveItemType;
use App\Form\RetrospectiveActionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class RetrospectiveController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }

    #[Route('/retrospectives', name: 'app_retrospectives')]
    public function index(): Response
    {
        $user = $this->getUser();
        
        // Get retrospectives where user is facilitator or team member
        $retrospectives = $this->entityManager->getRepository(Retrospective::class)
            ->createQueryBuilder('r')
            ->join('r.team', 't')
            ->leftJoin('t.teamMembers', 'tm')
            ->where('r.facilitator = :user OR tm.user = :user')
            ->andWhere('t.isActive = :active')
            ->setParameter('user', $user)
            ->setParameter('active', true)
            ->orderBy('r.scheduledAt', 'DESC')
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
            ->findBy(['team' => $team], ['scheduledAt' => 'DESC']);

        return $this->render('retrospective/team.html.twig', [
            'team' => $team,
            'retrospectives' => $retrospectives,
        ]);
    }

    #[Route('/retrospectives/create', name: 'app_retrospectives_create')]
    public function create(Request $request): Response
    {
        $user = $this->getUser();
        
        // Only Facilitator, Team Lead, and Administrator can create retrospectives
        if (!$user->hasRole('ROLE_ADMIN') && !$user->hasRole('ROLE_TEAM_LEAD') && !$user->hasRole('ROLE_FACILITATOR')) {
            throw $this->createAccessDeniedException('Only Administrators, Team Leads, and Facilitators can create retrospectives');
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
        $items = $this->entityManager->getRepository(RetrospectiveItem::class)
            ->findBy(['retrospective' => $retrospective], ['createdAt' => 'ASC']);

        $wellItems = array_filter($items, fn($item) => $item->isWell());
        $improveItems = array_filter($items, fn($item) => $item->isImprove());
        $actionItems = array_filter($items, fn($item) => $item->isAction());

        // Get actions
        $actions = $this->entityManager->getRepository(RetrospectiveAction::class)
            ->findBy(['retrospective' => $retrospective], ['createdAt' => 'ASC']);

        return $this->render('retrospective/show.html.twig', [
            'retrospective' => $retrospective,
            'wellItems' => $wellItems,
            'improveItems' => $improveItems,
            'actionItems' => $actionItems,
            'actions' => $actions,
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
            
            return $this->redirectToRoute('app_retrospectives_show', ['id' => $retrospective->getId()]);
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
        $retrospective->setUpdatedAt(new \DateTime());
        
        $this->entityManager->flush();
        
        $this->addFlash('success', '✅ Retrospective started!');
        
        return $this->redirectToRoute('app_retrospectives_show', ['id' => $retrospective->getId()]);
    }

    #[Route('/retrospectives/{id}/complete', name: 'app_retrospectives_complete')]
    public function complete(int $id): Response
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
        $retrospective->setCompletedAt(new \DateTime());
        $retrospective->setUpdatedAt(new \DateTime());
        
        $this->entityManager->flush();
        
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

        // Only active retrospectives can have items added
        if (!$retrospective->isActive()) {
            throw $this->createAccessDeniedException('Items can only be added to active retrospectives');
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

    #[Route('/retrospectives/{id}/add-action', name: 'app_retrospectives_add_action')]
    public function addAction(Request $request, int $id): Response
    {
        $retrospective = $this->entityManager->getRepository(Retrospective::class)->find($id);
        
        if (!$retrospective) {
            throw $this->createNotFoundException('Retrospective not found');
        }

        // Check if user has access to this retrospective
        if (!$this->hasTeamAccess($retrospective->getTeam())) {
            throw $this->createAccessDeniedException('You do not have access to this retrospective');
        }

        // Only facilitator or team owner can add actions
        if ($retrospective->getFacilitator() !== $this->getUser() && $retrospective->getTeam()->getOwner() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Only the facilitator or team owner can add actions');
        }

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

    private function hasTeamAccess(Team $team): bool
    {
        $user = $this->getUser();
        
        // Team owner has access
        if ($team->getOwner() === $user) {
            return true;
        }
        
        // Team members have access
        return $team->hasMember($user);
    }
}