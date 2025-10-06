<?php

namespace App\Admin\Controller;

use App\Entity\User;
use App\Entity\Role;
use App\Entity\UserRole;
use App\Repository\UserRepository;
use App\Repository\RoleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\ORM\EntityManagerInterface;

#[Route('/admin')]
class AdminUsersController extends AbstractController
{
    public function __construct(
        private UserRepository $userRepository,
        private RoleRepository $roleRepository,
        private EntityManagerInterface $entityManager
    ) {
    }

    #[Route('/users', name: 'admin_users')]
    public function users(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        // Pagination parameters
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 10;
        $offset = ($page - 1) * $limit;

        // Get total count
        $totalUsers = $this->userRepository->count([]);
        
        // Get users for current page
        $users = $this->userRepository->findBy(
            [],
            ['createdAt' => 'DESC'],
            $limit,
            $offset
        );

        // Calculate pagination info
        $totalPages = ceil($totalUsers / $limit);
        $hasNextPage = $page < $totalPages;
        $hasPrevPage = $page > 1;

        return $this->render('admin/users/index.html.twig', [
            'users' => $users,
            'current_page' => $page,
            'total_pages' => $totalPages,
            'total_users' => $totalUsers,
            'has_next_page' => $hasNextPage,
            'has_prev_page' => $hasPrevPage,
            'limit' => $limit,
        ]);
    }

    #[Route('/users/{id}/edit', name: 'admin_users_edit')]
    public function edit(User $user, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if ($request->isMethod('POST')) {
            $firstName = $request->request->get('firstName');
            $lastName = $request->request->get('lastName');
            $email = $request->request->get('email');
            $isActive = $request->request->getBoolean('isActive');
            $isVerified = $request->request->getBoolean('isVerified');
            $selectedRoles = $request->request->all('roles');

            // Update user data
            $user->setFirstName($firstName);
            $user->setLastName($lastName);
            $user->setEmail($email);
            $user->setIsActive($isActive);
            $user->setIsVerified($isVerified);

            // Handle roles - remove ROLE_USER from selected roles as it's automatic
            $selectedRoles = array_filter($selectedRoles, function($role) {
                return $role !== 'ROLE_USER';
            });

            // Remove all existing UserRole entities for this user
            foreach ($user->getUserRoles() as $userRole) {
                $this->entityManager->remove($userRole);
            }

            // Add new UserRole entities for selected roles
            foreach ($selectedRoles as $roleCode) {
                $role = $this->roleRepository->findOneBy(['code' => $roleCode]);
                if ($role) {
                    $userRole = new UserRole();
                    $userRole->setUser($user);
                    $userRole->setRole($role);
                    $userRole->setAssignedAt(new \DateTimeImmutable());
                    $userRole->setIsActive(true);
                    $userRole->setAssignedBy('admin');
                    
                    $this->entityManager->persist($userRole);
                }
            }

            $this->entityManager->flush();

            $this->addFlash('success', 'User updated successfully!');
            return $this->redirectToRoute('admin_users');
        }

        // Get all available roles for the form
        $availableRoles = $this->roleRepository->findAll();

        return $this->render('admin/users/edit.html.twig', [
            'user' => $user,
            'availableRoles' => $availableRoles,
        ]);
    }
}
