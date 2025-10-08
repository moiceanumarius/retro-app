<?php

namespace App\Controller;

use App\Entity\Organization;
use App\Entity\OrganizationMember;
use App\Entity\Role;
use App\Entity\Team;
use App\Entity\TeamMember;
use App\Entity\User;
use App\Entity\UserRole;
use App\Form\OrganizationType;
use App\Form\OrganizationMemberType;
use App\Repository\OrganizationRepository;
use App\Repository\OrganizationMemberRepository;
use App\Repository\UserRepository;
use App\Service\OrganizationService;
use App\Service\OrganizationMemberService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * OrganizationController
 * 
 * Controller for managing organizations in RetroApp system
 * Accessible ONLY for users with ROLE_ADMIN role
 * 
 * Main functionalities:
 * - List organizations (active and inactive for admin)
 * - Create new organizations
 * - Edit existing organizations
 * - Delete organizations (logical delete)
 * - Manage organization members
 * - Activate/deactivate organizations
 * 
 * Permissions:
 * - All methods check if user has ROLE_ADMIN
 * - If no privileges, AccessDeniedException is thrown
 */
#[Route('/organizations')]
final class OrganizationController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OrganizationRepository $organizationRepository,
        private OrganizationMemberRepository $organizationMemberRepository,
        private UserRepository $userRepository,
        private OrganizationService $organizationService,
        private OrganizationMemberService $organizationMemberService
    ) {
    }

    /**
     * List of organizations in system
     * Displays all organizations for admin with management options
     * 
     * @return Response Page with list of organizations
     */
    #[Route('', name: 'app_organizations')]
    public function index(): Response
    {
        // Permission check - ADMIN only
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $currentUser = $this->getUser();
        
        // Use OrganizationService to get user's organization
        $userOrganization = $this->organizationService->getUserOrganization($currentUser);
        
        // Statistics for user's organization
        $statistics = $this->organizationRepository->getStatistics($userOrganization);
        
        // Use OrganizationService to get user's organizations
        $organizations = $this->organizationService->getUserOrganizations($currentUser);
        
        // Recent organizations for sidebar
        $recentOrganizations = $this->organizationRepository->findRecent(5);
        
        // Most popular organizations
        $popularOrganizations = $this->organizationRepository->findMostPopular(5);

        return $this->render('organization/index.html.twig', [
            'organizations' => $organizations,
            'userOrganization' => $userOrganization,
            'statistics' => $statistics,
            'recent_organizations' => $recentOrganizations,
            'popular_organizations' => $popularOrganizations,
        ]);
    }

    /**
     * Crearea unei organizații noi
     * 
     * @param Request $request Request-ul cu datele formularului
     * @return Response Pagina de creare sau redirect la lista organizațiilor
     */
    #[Route('/create', name: 'app_organizations_create')]
    public function create(Request $request): Response
    {
        // Verificarea permisirii - doar ADMIN
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        
        $organization = new Organization();
        $organization->setOwner($this->getUser()); // Admin-current devine owner
        
        $form = $this->createForm(OrganizationType::class, $organization);
        $form->handleRequest($request);
        
        if ($form->isSubmitted() && $form->isValid()) {
            // Salvarea organizației
            $this->entityManager->persist($organization);
            
            // Adăugarea creatorului ca membru al organizației
            $member = new OrganizationMember();
            $member->setUser($this->getUser());
            $member->setOrganization($organization);
            $member->setRole('Admin');
            $member->setInvitedBy($this->getUser());
            
            $this->entityManager->persist($member);
            $this->entityManager->flush();
            
            $this->addFlash('success', '✅ Organization created successfully!');
            
            return $this->redirectToRoute('app_organizations_show', ['id' => $organization->getId()]);
        }
        
        return $this->render('organization/create.html.twig', [
            'form' => $form,
        ]);
    }

    /**
     * Detailed view of an organization
     * 
     * @param int $id Organization ID
     * @return Response Page with organization details
     */
    #[Route('/{id}', name: 'app_organizations_show', requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        // Permission check - ADMIN only
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        
        $organization = $this->organizationRepository->find($id);
        
        if (!$organization) {
            throw $this->createNotFoundException('Organization not found');
        }
        
        // Use OrganizationMemberService to get active members
        $members = $this->organizationMemberService->getActiveMembers($organization);
        
        // Use OrganizationMemberService to get statistics
        $memberStats = $this->organizationMemberService->getOrganizationStatistics($organization);
        
        // Teams belonging to organization
        $teams = $organization->getTeams();
        
        return $this->render('organization/show.html.twig', [
            'organization' => $organization,
            'members' => $members,
            'member_stats' => $memberStats,
            'teams' => $teams,
        ]);
    }

    /**
     * Editarea unei organizații existente
     * 
     * @param Request $request Request-ul cu datele formularului
     * @param int $id ID-ul organizației de editat
     * @return Response Pagina de editare sau redirect la detalii
     */
    #[Route('/{id}/edit', name: 'app_organizations_edit', requirements: ['id' => '\d+'])]
    public function edit(Request $request, int $id): Response
    {
        // Verificarea permisirii - doar ADMIN
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        
        $organization = $this->organizationRepository->find($id);
        
        if (!$organization) {
            throw $this->createNotFoundException('Organization not found');
        }
        
        $form = $this->createForm(OrganizationType::class, $organization);
        $form->handleRequest($request);
        
        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($organization);
            $this->entityManager->flush();
            
            $this->addFlash('success', '✅ Organization updated successfully!');
            
            return $this->redirectToRoute('app_organizations_show', ['id' => $organization->getId()]);
        }
        
        return $this->render('organization/edit.html.twig', [
            'organization' => $organization,
            'form' => $form,
        ]);
    }

    /**
     * Ștergerea (logical) a unei organizații
     * 
     * @param Request $request Request-ul pentru verificare CSRF
     * @param int $id ID-ul organizației de șters
     * @return Response Redirect la lista organizațiilor
     */
    #[Route('/{id}/delete', name: 'app_organizations_delete', requirements: ['id' => '\d+'])]
    public function delete(Request $request, int $id): Response
    {
        // Verificarea permisirii - doar ADMIN
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        
        $organization = $this->organizationRepository->find($id);
        
        if (!$organization) {
            throw $this->createNotFoundException('Organization not found');
        }
        
        // Verificarea dacă utilizatorul este owner al organizației
        $currentUser = $this->getUser();
        if ($organization->getOwner()->getId() === $currentUser->getId()) {
            $this->addFlash('error', '❌ You cannot delete your own organization. You can only edit it.');
            return $this->redirectToRoute('app_organizations_show', ['id' => $organization->getId()]);
        }
        
        // Verificarea token-ului CSRF pentru securitate
        if ($this->isCsrfTokenValid('delete_organization', $request->request->get('_token'))) {
            // Logical delete - marchează ca inactivă
            $this->organizationRepository->softDelete($organization);
            
            $this->addFlash('success', '✅ Organization deleted successfully!');
            
            return $this->redirectToRoute('app_organizations');
        } else {
            $this->addFlash('error', 'Invalid CSRF token');
            return $this->redirectToRoute('app_organizations_show', ['id' => $organization->getId()]);
        }
    }

    /**
     * Reactivarea unei organizații șters logic
     * 
     * @param Request $request Request-ul pentru verificare CSRF
     * @param int $id ID-ul organizației de reactivat
     * @return Response Redirect la detalii organizație
     */
    #[Route('/{id}/reactivate', name: 'app_organizations_reactivate', requirements: ['id' => '\d+'])]
    public function reactivate(Request $request, int $id): Response
    {
        // Verificarea permisirii - doar ADMIN
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        
        $organization = $this->organizationRepository->find($id);
        
        if (!$organization) {
            throw $this->createNotFoundException('Organization not found');
        }
        
        // Verificarea token-ului CSRF pentru securitate
        if ($this->isCsrfTokenValid('reactivate_organization', $request->request->get('_token'))) {
            $this->organizationRepository->reactivate($organization);
            
            $this->addFlash('success', '✅ Organization reactivated successfully!');
            
            return $this->redirectToRoute('app_organizations_show', ['id' => $organization->getId()]);
        } else {
            $this->addFlash('error', 'Invalid CSRF token');
            return $this->redirectToRoute('app_organizations');
        }
    }

    /**
     * Adăugarea unui membru nou în organizație
     * 
     * @param Request $request Request-ul cu datele formularului
     * @param int $id ID-ul organizației
     * @return Response Pagina de adăugare membru sau redirect
     */
    #[Route('/{id}/add-member', name: 'app_organizations_add_member', requirements: ['id' => '\d+'])]
    public function addMember(Request $request, int $id): Response
    {
        // Verificarea permisirii - doar ADMIN
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        
        $organization = $this->organizationRepository->find($id);
        
        if (!$organization) {
            throw $this->createNotFoundException('Organization not found');
        }
        
        $organizationMember = new OrganizationMember();
        $organizationMember->setOrganization($organization);
        $organizationMember->setInvitedBy($this->getUser());
        
        // Check if this is an AJAX request (JSON)
        if ($request->isXmlHttpRequest() || $request->request->has('_token')) {
            // Handle AJAX request
            try {
                $userId = $request->request->get('user');
                $role = $request->request->get('role', 'MEMBER');
                
                if (!$userId) {
                    return $this->json([
                        'success' => false,
                        'message' => 'User ID is required'
                    ], 400);
                }
                
                $user = $this->userRepository->find($userId);
                if (!$user) {
                    return $this->json([
                        'success' => false,
                        'message' => 'User not found'
                    ], 404);
                }
                
                // Use OrganizationMemberService to check if user can be added
                $canAdd = $this->organizationMemberService->canAddUserToOrganization($user, $organization);
                
                if (!$canAdd['allowed']) {
                    return $this->json([
                        'success' => false,
                        'message' => $canAdd['reason']
                    ], 400);
                }
                
                // Use OrganizationMemberService to add member
                $member = $this->organizationMemberService->addMemberToOrganization(
                    $user,
                    $organization,
                    $role,
                    $this->getUser()
                );
                
                return $this->json([
                    'success' => true,
                    'message' => 'User successfully added to organization',
                    'user' => [
                        'id' => $user->getId(),
                        'name' => $user->getFirstName() . ' ' . $user->getLastName(),
                        'email' => $user->getEmail(),
                        'role' => $role
                    ]
                ]);
                
            } catch (\Exception $e) {
                return $this->json([
                    'success' => false,
                    'message' => 'Error adding user to organization: ' . $e->getMessage()
                ], 500);
            }
        }
        
        // Handle regular form request
        $form = $this->createForm(OrganizationMemberType::class, $organizationMember);
        $form->handleRequest($request);
        
        if ($form->isSubmitted() && $form->isValid()) {
            $userToAdd = $organizationMember->getUser();
            
            // Use OrganizationMemberService to check if user can be added
            $canAdd = $this->organizationMemberService->canAddUserToOrganization($userToAdd, $organization);
            
            if (!$canAdd['allowed']) {
                $this->addFlash('error', '❌ ' . $canAdd['reason']);
                return $this->render('organization/add_member.html.twig', [
                    'organization' => $organization,
                    'form' => $form,
                ]);
            }
            
            // Use OrganizationMemberService to add member
            $this->organizationMemberService->addMemberToOrganization(
                $userToAdd,
                $organization,
                $organizationMember->getRole(),
                $this->getUser()
            );
            
            $this->addFlash('success', '✅ Member added successfully!');
            
            return $this->redirectToRoute('app_organizations_show_members', ['id' => $organization->getId()]);
        }
        
        return $this->render('organization/add_member.html.twig', [
            'organization' => $organization,
            'form' => $form,
        ]);
    }

    /**
     * Eliminarea unui membru din organizație
     * 
     * @param Request $request Request-ul pentru verificare CSRF
     * @param int $orgId ID-ul organizației
     * @param int $memberId ID-ul membrului de eliminat
     * @return Response Redirect la lista membrilor
     */
    #[Route('/{orgId}/remove-member/{memberId}', name: 'app_organizations_remove_member', requirements: ['orgId' => '\d+', 'memberId' => '\d+'])]
    public function removeMember(Request $request, int $orgId, int $memberId): Response
    {
        // Verificarea permisirii - doar ADMIN
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        
        $member = $this->organizationMemberRepository->find($memberId);
        
        if (!$member || $member->getOrganization()->getId() !== $orgId) {
            throw $this->createNotFoundException('Member not found');
        }
        
        $organization = $member->getOrganization();
        $currentUser = $this->getUser();
        
        // Verificarea dacă utilizatorul încearcă să se elimine pe sine
        if ($member->getUser()->getId() === $currentUser->getId()) {
            $this->addFlash('error', '❌ You cannot remove yourself from your organization.');
            return $this->redirectToRoute('app_organizations_show_members', ['id' => $orgId]);
        }
        
        // CSRF token validation for security
        if ($this->isCsrfTokenValid('remove_user_from_organization', $request->request->get('_token'))) {
            // Use OrganizationMemberService to remove member
            $this->organizationMemberService->removeMemberFromOrganization($member);
            
            $this->addFlash('success', '✅ Member removed successfully! User removed from all organization teams and role set to MEMBER.');
            
            return $this->redirectToRoute('app_organizations_show_members', ['id' => $orgId]);
        } else {
            $this->addFlash('error', 'Invalid CSRF token');
            return $this->redirectToRoute('app_organizations_show_members', ['id' => $orgId]);
        }
    }

    /**
     * Lista membrilor dintr-o organizație cu management
     * 
     * @param int $id ID-ul organizației
     * @return Response Pagina cu management-ul membrilor
     */
    #[Route('/{id}/members', name: 'app_organizations_show_members', requirements: ['id' => '\d+'])]
    public function showMembers(int $id): Response
    {
        // Verificarea permisirii - doar ADMIN
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        
        $organization = $this->organizationRepository->find($id);
        
        if (!$organization) {
            throw $this->createNotFoundException('Organization not found');
        }
        
        // Membrii activi și inactivi pentru management complet
        $activeMembers = $this->organizationMemberRepository->findActiveByOrganization($organization);
        $allMembers = $this->organizationMemberRepository->findAllByOrganization($organization);
        
        // Statistici pentru sidebar
        $memberStats = $this->organizationMemberRepository->getOrganizationStatistics($organization);
        
        // Membrii expirați care trebuie atenționați
        $expiredMembers = $this->organizationMemberRepository->findExpired($organization);
        
        return $this->redirectToRoute('app_organizations_show', ['id' => $id]);
    }

    /**
     * Căutarea organizațiilor după nume
     * AJAX endpoint pentru autocomplete
     * 
     * @param Request $request Request-ul cu termenul de căutare
     * @return Response JSON cu rezultatele căutării
     */
    #[Route('/search', name: 'app_organizations_search')]
    public function search(Request $request): Response
    {
        // Verificarea permisirii - doar ADMIN
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        
        $searchTerm = $request->query->get('q', '');
        
        if (strlen($searchTerm) < 2) {
            return $this->json([]);
        }
        
        $organizations = $this->organizationRepository->searchByName($searchTerm);
        
        $results = [];
        foreach ($organizations as $organization) {
            $results[] = [
                'id' => $organization->getId(),
                'name' => $organization->getName(),
                'description' => $organization->getDescription(),
                'active' => $organization->isActive(),
                'member_count' => $organization->getMemberCount(),
            ];
        }
        
        return $this->json($results);
    }

    /**
     * API endpoint pentru obținerea membrilor unei organizații
     * 
     * Returnează membrii activi ai unei organizații pentru dropdown-ul de eliminare
     * 
     * @param int $id ID-ul organizației
     * @return JsonResponse Lista membrilor organizației
     */
    #[Route('/{id}/api/members', name: 'app_api_organization_members', methods: ['GET'])]
    public function getOrganizationMembers(int $id): JsonResponse
    {
        // Verificarea permisirii - doar ADMIN
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        
        try {
            $organization = $this->organizationRepository->find($id);
            
            if (!$organization) {
                return $this->json([
                    'success' => false,
                    'message' => 'Organization not found',
                    'members' => []
                ], 404);
            }
            
            // Obținerea membrilor activi ai organizației
            $members = $this->organizationMemberRepository->findActiveByOrganization($organization);
            
            $results = [];
            $organizationOwner = $organization->getOwner();
            
            foreach ($members as $member) {
                $user = $member->getUser();
                
                // Skip organization owner - they cannot be removed
                if ($user === $organizationOwner) {
                    continue;
                }
                
                // Obținerea rolului principal al utilizatorului
                $primaryRole = 'N/A';
                foreach ($user->getUserRoles() as $userRole) {
                    if ($userRole->isActive() && $userRole->getRole()) {
                        $roleCode = $userRole->getRole()->getCode();
                        // Extragem doar numele rolului fără ROLE_ prefix
                        $primaryRole = str_replace('ROLE_', '', $roleCode);
                        break; // Luăm primul rol activ găsit
                    }
                }
                
                $results[] = [
                    'id' => $user->getId(),
                    'memberId' => $member->getId(), // ID-ul membrului în organizație
                    'firstName' => $user->getFirstName(),
                    'lastName' => $user->getLastName(),
                    'email' => $user->getEmail(),
                    'role' => $primaryRole,
                    'organizationRole' => $member->getRole(),
                ];
            }
            
            return $this->json([
                'success' => true,
                'members' => $results,
                'count' => count($results)
            ]);
            
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Error loading organization members: ' . $e->getMessage(),
                'members' => []
            ], 500);
        }
    }

    /**
     * API endpoint pentru obținerea utilizatorilor cu roluri elevated pentru dropdown
     * 
     * Returnează utilizatorii care nu au rolul MEMBER (ADMIN, SUPERVISOR, FACILITATOR)
     * Utilizat în dropdown-ul de adăugare utilizatori la organizații
     * 
     * @return JsonResponse Lista utilizatorilor cu roluri elevated
     */
    #[Route('/api/users/elevated-roles', name: 'app_api_users_elevated_roles', methods: ['GET'])]
    public function getElevatedRolesUsers(): JsonResponse
    {
        // Verificarea permisirii - doar ADMIN
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        
        try {
            // Obținerea tuturor utilizatorilor care NU au organizație activă
            // Folosim o subquery pentru a exclude utilizatorii cu organizații active
            $users = $this->userRepository->createQueryBuilder('u')
                ->where('u.id NOT IN (
                    SELECT DISTINCT u2.id 
                    FROM App\Entity\User u2 
                    JOIN u2.organizationMemberships om2 
                    WHERE om2.isActive = :active 
                    AND om2.leftAt IS NULL
                )')
                ->setParameter('active', true)
                ->orderBy('u.lastName', 'ASC')
                ->addOrderBy('u.firstName', 'ASC')
                ->getQuery()
                ->getResult();

            $results = [];
            foreach ($users as $user) {
                // Obținerea rolului principal al utilizatorului
                $primaryRole = 'N/A';
                foreach ($user->getUserRoles() as $userRole) {
                    if ($userRole->isActive() && $userRole->getRole()) {
                        $roleCode = $userRole->getRole()->getCode();
                        // Extragem doar numele rolului fără ROLE_ prefix
                        $primaryRole = str_replace('ROLE_', '', $roleCode);
                        break; // Luăm primul rol activ găsit
                    }
                }
                
                $results[] = [
                    'id' => $user->getId(),
                    'firstName' => $user->getFirstName(),
                    'lastName' => $user->getLastName(),
                    'email' => $user->getEmail(),
                    'role' => $primaryRole,
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
