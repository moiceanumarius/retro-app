<?php

namespace App\Controller;

use App\Entity\Role;
use App\Entity\User;
use App\Entity\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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

        $userId = $request->request->get('user_id');
        $roleId = $request->request->get('role_id');

        $user = $this->entityManager->getRepository(User::class)->find($userId);
        $role = $this->entityManager->getRepository(Role::class)->find($roleId);

        if (!$user || !$role) {
            $this->addFlash('error', 'User or role not found.');
            return $this->redirectToRoute('app_roles');
        }

        // Check if user already has this role
        $existingUserRole = $this->entityManager->getRepository(UserRole::class)->findOneBy([
            'user' => $user,
            'role' => $role
        ]);

        if ($existingUserRole) {
            $this->addFlash('warning', 'User already has this role.');
            return $this->redirectToRoute('app_roles');
        }

        // Create new UserRole
        $userRole = new UserRole();
        $userRole->setUser($user);
        $userRole->setRole($role);
        $userRole->setAssignedAt(new \DateTimeImmutable());
        $userRole->setAssignedBy($this->getUser()->getEmail());

        $this->entityManager->persist($userRole);
        $this->entityManager->flush();

        $this->addFlash('success', "Role '{$role->getName()}' assigned to '{$user->getFullName()}' successfully.");

        return $this->redirectToRoute('app_roles');
    }

    #[Route('/roles/remove/{userRoleId}', name: 'app_roles_remove', methods: ['POST'])]
    public function removeRole(int $userRoleId): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $userRole = $this->entityManager->getRepository(UserRole::class)->find($userRoleId);

        if (!$userRole) {
            $this->addFlash('error', 'User role not found.');
            return $this->redirectToRoute('app_roles');
        }

        $userName = $userRole->getUser()->getFullName();
        $roleName = $userRole->getRole()->getName();

        $this->entityManager->remove($userRole);
        $this->entityManager->flush();

        $this->addFlash('success', "Role '{$roleName}' removed from '{$userName}' successfully.");

        return $this->redirectToRoute('app_roles');
    }
}
