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
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $roles = $this->entityManager->getRepository(Role::class)->findAll();
        $users = $this->entityManager->getRepository(User::class)->findAll();

        return $this->render('role/index.html.twig', [
            'roles' => $roles,
            'users' => $users,
        ]);
    }

    #[Route('/roles/assign', name: 'app_roles_assign', methods: ['POST'])]
    public function assignRole(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        

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
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

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
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        
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

        $qb = $this->entityManager->createQueryBuilder()
            ->select('ur')
            ->from(UserRole::class, 'ur')
            ->leftJoin('ur.user', 'u')
            ->leftJoin('ur.role', 'r')
            ->where('ur.isActive = :active')
            ->setParameter('active', true);

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
            $data[] = [
                'userName' => $userRole->getUser()->getFullName(),
                'email' => $userRole->getUser()->getEmail(),
                'roleName' => $userRole->getRole()->getName(),
                'assignedAt' => $userRole->getAssignedAt()->format('M d, Y H:i'),
                'assignedBy' => $userRole->getAssignedBy() ?? 'System',
                'status' => $userRole->isActive() ? 'Active' : 'Inactive',
                'actions' => '<form method="post" action="' . $this->generateUrl('app_roles_remove', ['userRoleId' => $userRole->getId()]) . '" style="display: inline;" onsubmit="return confirm(\'Are you sure?\')">
                    <input type="hidden" name="_token" value="' . $this->container->get('security.csrf.token_manager')->getToken('remove_role') . '">
                    <button type="submit" class="btn-modern btn-danger-modern" style="padding: 4px 8px; font-size: 12px;">Remove</button>
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
}
