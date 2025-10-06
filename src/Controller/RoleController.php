<?php

namespace App\Controller;

use App\Entity\Role;
use App\Entity\User;
use App\Entity\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RoleController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }

    #[Route('/roles', name: 'app_roles')]
    public function index(): Response
    {
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_SUPERVISOR')) {
            throw $this->createAccessDeniedException('Access denied. Admin or Supervisor role required.');
        }

        $allRoles = $this->entityManager->getRepository(Role::class)->findAll();
        
        // Get only users from the current user's organization that can be managed
        $currentUser = $this->getUser();
        $users = [];
        
        // Filter roles based on current user's role hierarchy
        $roles = $this->filterRolesByHierarchy($allRoles, $currentUser);
        
        // Find the current user's organization
        foreach ($currentUser->getOrganizationMemberships() as $membership) {
            if ($membership->isActive() && $membership->getLeftAt() === null) {
                $organization = $membership->getOrganization();
                // Get all users from this organization
                $allUsers = $this->entityManager->getRepository(User::class)
                    ->createQueryBuilder('u')
                    ->leftJoin('u.organizationMemberships', 'om')
                    ->where('om.organization = :organization')
                    ->andWhere('om.isActive = :active')
                    ->andWhere('om.leftAt IS NULL')
                    ->setParameter('organization', $organization)
                    ->setParameter('active', true)
                    ->orderBy('u.lastName', 'ASC')
                    ->addOrderBy('u.firstName', 'ASC')
                    ->getQuery()
                    ->getResult();
                
                // Filter users based on role hierarchy - only show users with lower or equal roles
                $users = $this->filterUsersByRoleHierarchy($allUsers, $currentUser);
                break;
            }
        }

        // Count users per role (filtered by organization)
        $roleCounts = [];
        $userOrganization = null;
        
        // Get current user's organization
        foreach ($currentUser->getOrganizationMemberships() as $membership) {
            if ($membership->isActive() && $membership->getLeftAt() === null) {
                $userOrganization = $membership->getOrganization();
                break;
            }
        }
        
        foreach ($roles as $role) {
            if ($userOrganization) {
                // Count only users from the same organization
                $count = $this->entityManager->getRepository(UserRole::class)
                    ->createQueryBuilder('ur')
                    ->select('COUNT(ur.id)')
                    ->leftJoin('ur.user', 'u')
                    ->leftJoin('u.organizationMemberships', 'om')
                    ->where('ur.role = :role')
                    ->andWhere('ur.isActive = :active')
                    ->andWhere('om.organization = :organization')
                    ->andWhere('om.isActive = :orgActive')
                    ->andWhere('om.leftAt IS NULL')
                    ->setParameter('role', $role)
                    ->setParameter('active', true)
                    ->setParameter('organization', $userOrganization)
                    ->setParameter('orgActive', true)
                    ->getQuery()
                    ->getSingleScalarResult();
            } else {
                // If user has no organization, return 0
                $count = 0;
            }
            
            $roleCounts[$role->getCode()] = $count;
        }

        return $this->render('role/index.html.twig', [
            'roles' => $roles,
            'users' => $users,
            'roleCounts' => $roleCounts,
        ]);
    }

    #[Route('/roles/assign', name: 'app_roles_assign', methods: ['POST'])]
    public function assignRole(Request $request): Response
    {
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_SUPERVISOR')) {
            throw $this->createAccessDeniedException('Access denied. Admin or Supervisor role required.');
        }
        

        // CSRF protection
        if (!$this->isCsrfTokenValid('assign_role', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_roles');
        }

        $userId = $request->request->get('user_id');
        $roleId = $request->request->get('role_id');

        $user = $this->entityManager->getRepository(User::class)->find($userId);
        $role = $this->entityManager->getRepository(Role::class)->find($roleId);

        if (!$user || !$role) {
            $this->addFlash('error', 'User or role not found.');
            return $this->redirectToRoute('app_roles');
        }

        // Check if user has any existing role assignment
        $existingUserRole = $this->entityManager->getRepository(UserRole::class)->findOneBy([
            'user' => $user
        ]);

        if ($existingUserRole) {
            // Update existing role assignment with new role
            $existingUserRole->setRole($role);
            $existingUserRole->setAssignedAt(new \DateTimeImmutable());
            $existingUserRole->setAssignedBy($this->getUser()->getEmail());
            $existingUserRole->setIsActive(true);
            $userRole = $existingUserRole;
        } else {
            // Create new UserRole
            $userRole = new UserRole();
            $userRole->setUser($user);
            $userRole->setRole($role);
            $userRole->setAssignedAt(new \DateTimeImmutable());
            $userRole->setAssignedBy($this->getUser()->getEmail());
        }

        $this->entityManager->persist($userRole);
        $this->entityManager->flush();

        if ($existingUserRole) {
            $this->addFlash('success', "✅ User role was updated successfully!");
        } else {
            $this->addFlash('success', "✅ New user role was added successfully!");
        }

        return $this->redirectToRoute('app_roles');
    }

    #[Route('/roles/remove/{userRoleId}', name: 'app_roles_remove', methods: ['POST'])]
    public function removeRole(Request $request, int $userRoleId): Response
    {
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_SUPERVISOR')) {
            throw $this->createAccessDeniedException('Access denied. Admin or Supervisor role required.');
        }

        // CSRF protection
        if (!$this->isCsrfTokenValid('remove_role', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_roles');
        }

        $userRole = $this->entityManager->getRepository(UserRole::class)->find($userRoleId);

        if (!$userRole) {
            $this->addFlash('error', 'User role not found.');
            return $this->redirectToRoute('app_roles');
        }

        $userName = $userRole->getUser()->getFullName();
        $roleName = $userRole->getRole()->getName();

        $this->entityManager->remove($userRole);
        $this->entityManager->flush();

        $this->addFlash('success', "✅ User role was removed successfully!");

        return $this->redirectToRoute('app_roles');
    }

    #[Route('/roles/data', name: 'app_roles_data', methods: ['GET'])]
    public function getRolesData(Request $request): JsonResponse
    {
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_SUPERVISOR')) {
            throw $this->createAccessDeniedException('Access denied. Admin or Supervisor role required.');
        }
        
        // Debug logging
        error_log('DataTables request received: ' . json_encode($request->query->all()));

        $draw = (int) $request->query->get('draw', 1);
        $start = (int) $request->query->get('start', 0);
        $length = (int) $request->query->get('length', 20);
        
        // Handle search parameter properly - get all query params first
        $allParams = $request->query->all();
        $searchValue = $allParams['search']['value'] ?? '';
        
        // Handle order parameter properly
        $orderColumn = isset($allParams['order'][0]['column']) ? (int) $allParams['order'][0]['column'] : 3;
        $orderDir = $allParams['order'][0]['dir'] ?? 'desc';

        // Get current user's organization
        $currentUser = $this->getUser();
        $organization = null;
        
        foreach ($currentUser->getOrganizationMemberships() as $membership) {
            if ($membership->isActive() && $membership->getLeftAt() === null) {
                $organization = $membership->getOrganization();
                break;
            }
        }

        $qb = $this->entityManager->createQueryBuilder()
            ->select('ur')
            ->from(UserRole::class, 'ur')
            ->leftJoin('ur.user', 'u')
            ->leftJoin('ur.role', 'r')
            ->leftJoin('u.organizationMemberships', 'om')
            ->where('ur.isActive = :active')
            ->setParameter('active', true);
            
        // Filter by organization if user belongs to one
        if ($organization) {
            $qb->andWhere('om.organization = :organization')
               ->andWhere('om.isActive = :orgActive')
               ->andWhere('om.leftAt IS NULL')
               ->setParameter('organization', $organization)
               ->setParameter('orgActive', true);
        } else {
            // If user has no organization, return empty result
            $qb->andWhere('1 = 0');
        }

        // Apply role hierarchy filtering - only show users with roles that can be managed by current user
        $this->applyRoleHierarchyFilter($qb, $currentUser);

        // Apply global search
        if (!empty($searchValue)) {
            $qb->andWhere('u.firstName LIKE :search OR u.lastName LIKE :search OR u.email LIKE :search')
               ->setParameter('search', '%' . $searchValue . '%');
        }

        // Get total count
        $totalQb = clone $qb;
        $totalRecords = $totalQb->select('COUNT(ur.id)')->getQuery()->getSingleScalarResult();

        // Apply sorting
        $columns = ['u.firstName', 'u.email', 'r.name', 'ur.assignedAt', 'ur.assignedBy', 'ur.isActive'];
        $orderColumnName = $columns[$orderColumn] ?? 'ur.assignedAt';
        $qb->orderBy($orderColumnName, $orderDir);

        // Apply pagination
        $qb->setFirstResult($start)
           ->setMaxResults($length);

        $userRoles = $qb->getQuery()->getResult();

        $data = [];
        foreach ($userRoles as $userRole) {
            // Role badge styling
            $roleClass = match($userRole->getRole()->getCode()) {
                'ROLE_ADMIN' => 'badge-danger-modern',
                'ROLE_SUPERVISOR' => 'badge-warning-modern',
                'ROLE_FACILITATOR' => 'badge-primary-modern',
                'ROLE_MEMBER' => 'badge-success-modern',
                default => 'badge-primary-modern'
            };
            
            $roleBadge = '<span class="badge-modern ' . $roleClass . '">' . $userRole->getRole()->getName() . '</span>';
            
            // Status badge
            $statusBadge = $userRole->isActive() 
                ? '<span class="badge-modern badge-success-modern">Active</span>'
                : '<span class="badge-modern badge-danger-modern">Inactive</span>';
            
            $data[] = [
                'userName' => $userRole->getUser()->getFullName(),
                'email' => $userRole->getUser()->getEmail(),
                'roleName' => $roleBadge,
                'assignedAt' => $userRole->getAssignedAt()->format('M d, Y H:i'),
                'assignedBy' => $userRole->getAssignedBy() ?? 'System',
                'status' => $statusBadge,
                'actions' => '<form method="post" action="' . $this->generateUrl('app_roles_remove', ['userRoleId' => $userRole->getId()]) . '" style="display: inline;" onsubmit="return confirm(\'Are you sure?\')">
                    <input type="hidden" name="_token" value="' . $this->container->get('security.csrf.token_manager')->getToken('remove_role') . '">
                    <button type="submit" class="btn-modern btn-danger-modern">Remove</button>
                </form>'
            ];
        }

        $response = [
            'draw' => $draw,
            'recordsTotal' => $totalRecords,
            'recordsFiltered' => $totalRecords,
            'data' => $data
        ];
        
        // Debug logging
        error_log('DataTables response: ' . json_encode($response));
        
        return new JsonResponse($response);
    }

    /**
     * Filter users based on role hierarchy
     * Only show users with roles that are lower or equal to the current user's role
     * 
     * @param array $users Array of User entities
     * @param User $currentUser The currently logged in user
     * @return array Filtered array of users
     */
    private function filterUsersByRoleHierarchy(array $users, User $currentUser): array
    {
        // Define role hierarchy (higher roles can manage lower roles)
        $roleHierarchy = [
            'ROLE_MEMBER' => 1,
            'ROLE_FACILITATOR' => 2,
            'ROLE_SUPERVISOR' => 3,
            'ROLE_ADMIN' => 4,
        ];

        // Get current user's highest role level
        $currentUserRoleLevel = 0;
        $currentUserRoles = $currentUser->getAllRolesIncludingInherited();
        
        foreach ($currentUserRoles as $role) {
            if (isset($roleHierarchy[$role])) {
                $currentUserRoleLevel = max($currentUserRoleLevel, $roleHierarchy[$role]);
            }
        }

        // Filter users - only include users with roles that are lower or equal to current user's role
        $filteredUsers = [];
        foreach ($users as $user) {
            // Skip the current user (can't manage themselves)
            if ($user->getId() === $currentUser->getId()) {
                continue;
            }

            $userRoleLevel = 0;
            $userRoles = $user->getAllRolesIncludingInherited();
            
            foreach ($userRoles as $role) {
                if (isset($roleHierarchy[$role])) {
                    $userRoleLevel = max($userRoleLevel, $roleHierarchy[$role]);
                }
            }

            // Only include users with lower or equal role level
            if ($userRoleLevel <= $currentUserRoleLevel) {
                $filteredUsers[] = $user;
            }
        }

        return $filteredUsers;
    }

    /**
     * Apply role hierarchy filtering to a query builder
     * Only show users with roles that can be managed by the current user
     * 
     * @param \Doctrine\ORM\QueryBuilder $qb The query builder to modify
     * @param User $currentUser The currently logged in user
     */
    private function applyRoleHierarchyFilter(\Doctrine\ORM\QueryBuilder $qb, User $currentUser): void
    {
        // Define role hierarchy (higher roles can manage lower roles)
        $roleHierarchy = [
            'ROLE_MEMBER' => 1,
            'ROLE_FACILITATOR' => 2,
            'ROLE_SUPERVISOR' => 3,
            'ROLE_ADMIN' => 4,
        ];

        // Get current user's highest role level
        $currentUserRoleLevel = 0;
        $currentUserRoles = $currentUser->getAllRolesIncludingInherited();
        
        foreach ($currentUserRoles as $role) {
            if (isset($roleHierarchy[$role])) {
                $currentUserRoleLevel = max($currentUserRoleLevel, $roleHierarchy[$role]);
            }
        }

        // Build role codes that current user can manage (lower or equal levels)
        $manageableRoleCodes = [];
        foreach ($roleHierarchy as $roleCode => $level) {
            if ($level <= $currentUserRoleLevel) {
                $manageableRoleCodes[] = $roleCode;
            }
        }

        // Apply filtering - only show users with manageable roles
        if (!empty($manageableRoleCodes)) {
            $qb->andWhere('r.code IN (:manageableRoles)')
               ->setParameter('manageableRoles', $manageableRoleCodes);
        } else {
            // If no manageable roles, return empty result
            $qb->andWhere('1 = 0');
        }

        // Exclude current user from results (can't manage themselves)
        $qb->andWhere('u.id != :currentUserId')
           ->setParameter('currentUserId', $currentUser->getId());
    }

    /**
     * Filter roles based on role hierarchy
     * Only show roles that are lower than the current user's role
     * 
     * @param array $allRoles Array of Role entities
     * @param User $currentUser The currently logged in user
     * @return array Filtered array of roles
     */
    private function filterRolesByHierarchy(array $allRoles, User $currentUser): array
    {
        // Define role hierarchy (higher roles can assign lower roles)
        $roleHierarchy = [
            'ROLE_MEMBER' => 1,
            'ROLE_FACILITATOR' => 2,
            'ROLE_SUPERVISOR' => 3,
            'ROLE_ADMIN' => 4,
        ];

        // Get current user's highest role level
        $currentUserRoleLevel = 0;
        $currentUserRoles = $currentUser->getAllRolesIncludingInherited();
        
        foreach ($currentUserRoles as $role) {
            if (isset($roleHierarchy[$role])) {
                $currentUserRoleLevel = max($currentUserRoleLevel, $roleHierarchy[$role]);
            }
        }

        // Filter roles - only include roles that are lower than current user's role
        $filteredRoles = [];
        foreach ($allRoles as $role) {
            $roleCode = $role->getCode();
            if (isset($roleHierarchy[$roleCode])) {
                $roleLevel = $roleHierarchy[$roleCode];
                // Only include roles that are lower than current user's role
                if ($roleLevel < $currentUserRoleLevel) {
                    $filteredRoles[] = $role;
                }
            }
        }

        return $filteredRoles;
    }
}
