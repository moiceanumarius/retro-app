<?php

namespace App\Admin\Controller;

use App\Entity\User;
use App\Entity\Role;
use App\Entity\UserRole;
use App\Entity\DeletedUser;
use App\Repository\UserRepository;
use App\Repository\RoleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[Route('/admin')]
class AdminUsersController extends AbstractController
{
    public function __construct(
        private UserRepository $userRepository,
        private RoleRepository $roleRepository,
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher
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
            $selectedRole = $request->request->get('role');
            $newPassword = $request->request->get('newPassword');
            $confirmPassword = $request->request->get('confirmPassword');

            // Validate password if provided
            if (!empty($newPassword) || !empty($confirmPassword)) {
                if (empty($newPassword) || empty($confirmPassword)) {
                    $this->addFlash('error', 'Both password fields must be filled to change password.');
                    return $this->render('admin/users/edit.html.twig', [
                        'user' => $user,
                        'availableRoles' => $this->roleRepository->findAll(),
                    ]);
                }
                
                if (strlen($newPassword) < 6) {
                    $this->addFlash('error', 'Password must be at least 6 characters long.');
                    return $this->render('admin/users/edit.html.twig', [
                        'user' => $user,
                        'availableRoles' => $this->roleRepository->findAll(),
                    ]);
                }
                
                if ($newPassword !== $confirmPassword) {
                    $this->addFlash('error', 'Passwords do not match.');
                    return $this->render('admin/users/edit.html.twig', [
                        'user' => $user,
                        'availableRoles' => $this->roleRepository->findAll(),
                    ]);
                }
                
                // Hash and set new password
                $hashedPassword = $this->passwordHasher->hashPassword($user, $newPassword);
                $user->setPassword($hashedPassword);
            }

            // Update user data
            $user->setFirstName($firstName);
            $user->setLastName($lastName);
            $user->setEmail($email);
            $user->setIsActive($isActive);
            $user->setIsVerified($isVerified);

            // Handle role - only update if role actually changed
            if ($selectedRole && $selectedRole !== 'ROLE_USER') {
                // Get current user's highest role
                $currentHighestRole = null;
                $userRoles = $user->getUserRoles();
                foreach ($userRoles as $userRole) {
                    if ($userRole->getRole()) {
                        $currentHighestRole = $userRole->getRole()->getCode();
                        break; // Get the first (highest) role
                    }
                }

                // Only update if the role actually changed
                if ($currentHighestRole !== $selectedRole) {
                    // Remove all existing UserRole entities for this user
                    foreach ($user->getUserRoles() as $userRole) {
                        $this->entityManager->remove($userRole);
                    }

                    // Add new UserRole entity for selected role
                    $role = $this->roleRepository->findOneBy(['code' => $selectedRole]);
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
            }

            $this->entityManager->flush();

            $successMessage = 'User updated successfully!';
            if (!empty($newPassword)) {
                $successMessage .= ' Password has been changed.';
            }
            
            $this->addFlash('success', $successMessage);
            return $this->redirectToRoute('admin_users_edit', ['id' => $user->getId()]);
        }

        // Get all available roles for the form
        $availableRoles = $this->roleRepository->findAll();

        return $this->render('admin/users/edit.html.twig', [
            'user' => $user,
            'availableRoles' => $availableRoles,
        ]);
    }

    #[Route('/users/add', name: 'admin_users_add')]
    public function add(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if ($request->isMethod('POST')) {
            $firstName = $request->request->get('firstName');
            $lastName = $request->request->get('lastName');
            $email = $request->request->get('email');
            $password = $request->request->get('password');
            $confirmPassword = $request->request->get('confirmPassword');
            $isActive = $request->request->getBoolean('isActive');
            $isVerified = $request->request->getBoolean('isVerified');
            $selectedRole = $request->request->get('role');

            // Validate required fields
            if (empty($firstName) || empty($lastName) || empty($email) || empty($password)) {
                $this->addFlash('error', 'All required fields must be filled.');
                return $this->render('admin/users/add.html.twig', [
                    'availableRoles' => $this->roleRepository->findAll(),
                ]);
            }

            // Validate password
            if ($password !== $confirmPassword) {
                $this->addFlash('error', 'Passwords do not match.');
                return $this->render('admin/users/add.html.twig', [
                    'availableRoles' => $this->roleRepository->findAll(),
                ]);
            }

            if (strlen($password) < 6) {
                $this->addFlash('error', 'Password must be at least 6 characters long.');
                return $this->render('admin/users/add.html.twig', [
                    'availableRoles' => $this->roleRepository->findAll(),
                ]);
            }

            // Check if email already exists
            $existingUser = $this->userRepository->findOneBy(['email' => $email]);
            if ($existingUser) {
                $this->addFlash('error', 'A user with this email already exists.');
                return $this->render('admin/users/add.html.twig', [
                    'availableRoles' => $this->roleRepository->findAll(),
                ]);
            }

            // Create new user
            $user = new User();
            $user->setFirstName($firstName);
            $user->setLastName($lastName);
            $user->setEmail($email);
            $user->setIsActive($isActive);
            $user->setIsVerified($isVerified);
            $user->setCreatedAt(new \DateTimeImmutable());
            $user->setUpdatedAt(new \DateTimeImmutable());

            // Hash password
            $hashedPassword = $this->passwordHasher->hashPassword($user, $password);
            $user->setPassword($hashedPassword);

            // Handle role
            if ($selectedRole && $selectedRole !== 'ROLE_USER') {
                $role = $this->roleRepository->findOneBy(['code' => $selectedRole]);
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

            $this->entityManager->persist($user);
            $this->entityManager->flush();

            $this->addFlash('success', 'User created successfully!');
            return $this->redirectToRoute('admin_users_edit', ['id' => $user->getId()]);
        }

        return $this->render('admin/users/add.html.twig', [
            'availableRoles' => $this->roleRepository->findAll(),
        ]);
    }

    #[Route('/users/{id}/delete', name: 'admin_users_delete', methods: ['POST'])]
    public function delete(User $user, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        // CSRF protection
        if (!$this->isCsrfTokenValid('delete_user', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_users');
        }

        // Prevent admin from deleting themselves
        if ($user->getId() === $this->getUser()->getId()) {
            $this->addFlash('error', 'You cannot delete your own account.');
            return $this->redirectToRoute('admin_users');
        }

        // Check if user is owner of any organization
        $ownedOrganizations = $this->entityManager->getRepository(\App\Entity\Organization::class)
            ->findBy(['owner' => $user]);
        
        if (!empty($ownedOrganizations)) {
            $orgNames = array_map(fn($org) => $org->getName(), $ownedOrganizations);
            $this->addFlash('error', 'Cannot delete user. User is owner of organization(s): ' . implode(', ', $orgNames) . '. Please transfer ownership first.');
            return $this->redirectToRoute('admin_users');
        }

        // Check if user is owner of any team
        $ownedTeams = $this->entityManager->getRepository(\App\Entity\Team::class)
            ->findBy(['owner' => $user, 'isActive' => true]);
        
        if (!empty($ownedTeams)) {
            $teamNames = array_map(fn($team) => $team->getName(), $ownedTeams);
            $this->addFlash('error', 'Cannot delete user. User is owner of team(s): ' . implode(', ', $teamNames) . '. Please transfer ownership first.');
            return $this->redirectToRoute('admin_users');
        }

        // Create deleted user record
        $deletedUser = new DeletedUser();
        $deletedUser->setOriginalId($user->getId());
        $deletedUser->setEmail($user->getEmail());
        $deletedUser->setRoles($user->getRoles());
        $deletedUser->setPassword($user->getPassword());
        $deletedUser->setFirstName($user->getFirstName());
        $deletedUser->setLastName($user->getLastName());
        $deletedUser->setCreatedAt($user->getCreatedAt());
        $deletedUser->setUpdatedAt($user->getUpdatedAt());
        $deletedUser->setIsVerified($user->isVerified());
        $deletedUser->setAvatar($user->getAvatar());
        $deletedUser->setBio($user->getBio());
        $deletedUser->setTimezone($user->getTimezone());
        $deletedUser->setLanguage($user->getLanguage());
        $deletedUser->setIsActive($user->isActive());
        $deletedUser->setDeletedAt(new \DateTimeImmutable());
        $deletedUser->setDeletedBy($this->getUser()->getEmail());

        // Save deleted user record
        $this->entityManager->persist($deletedUser);

        // Remove all user roles first
        $userRoles = $this->entityManager->getRepository(UserRole::class)->findBy(['user' => $user]);
        foreach ($userRoles as $userRole) {
            $this->entityManager->remove($userRole);
        }

        // Remove user
        $this->entityManager->remove($user);
        $this->entityManager->flush();

        $this->addFlash('success', 'User deleted successfully and moved to deleted users.');
        return $this->redirectToRoute('admin_users');
    }

    #[Route('/users/{id}/remove-from-org', name: 'admin_users_remove_from_org', methods: ['POST'])]
    public function removeFromOrganization(User $user, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        // CSRF protection
        if (!$this->isCsrfTokenValid('remove_from_org', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_users_edit', ['id' => $user->getId()]);
        }

        // Check if user is owner of any organization
        $ownedOrganizations = $this->entityManager->getRepository(\App\Entity\Organization::class)
            ->findBy(['owner' => $user]);
        
        if (!empty($ownedOrganizations)) {
            $orgNames = array_map(fn($org) => $org->getName(), $ownedOrganizations);
            $this->addFlash('error', 'Cannot remove user from organization. User is owner of organization(s): ' . implode(', ', $orgNames) . '. Please transfer ownership first.');
            return $this->redirectToRoute('admin_users_edit', ['id' => $user->getId()]);
        }

        // Check if user is owner of any team
        $ownedTeams = $this->entityManager->getRepository(\App\Entity\Team::class)
            ->findBy(['owner' => $user, 'isActive' => true]);
        
        if (!empty($ownedTeams)) {
            $teamNames = array_map(fn($team) => $team->getName(), $ownedTeams);
            $this->addFlash('error', 'Cannot remove user from organization. User is owner of team(s): ' . implode(', ', $teamNames) . '. Please transfer ownership first.');
            return $this->redirectToRoute('admin_users_edit', ['id' => $user->getId()]);
        }

        // Find and remove organization memberships
        $organizationMemberships = $this->entityManager->getRepository(\App\Entity\OrganizationMember::class)
            ->findBy(['user' => $user, 'isActive' => true]);

        if (empty($organizationMemberships)) {
            $this->addFlash('warning', 'User is not a member of any organization.');
            return $this->redirectToRoute('admin_users_edit', ['id' => $user->getId()]);
        }

        $removedOrgCount = 0;
        $removedTeamCount = 0;

        // Remove from organizations
        foreach ($organizationMemberships as $membership) {
            $membership->setIsActive(false);
            $membership->setLeftAt(new \DateTimeImmutable());
            $removedOrgCount++;
        }

        // Find and remove team memberships
        $teamMemberships = $this->entityManager->getRepository(\App\Entity\TeamMember::class)
            ->findBy(['user' => $user, 'isActive' => true]);

        foreach ($teamMemberships as $teamMembership) {
            $teamMembership->setIsActive(false);
            $teamMembership->setLeftAt(new \DateTimeImmutable());
            $removedTeamCount++;
        }

        $this->entityManager->flush();

        $message = "User has been removed from {$removedOrgCount} organization(s)";
        if ($removedTeamCount > 0) {
            $message .= " and {$removedTeamCount} team(s)";
        }
        $message .= ".";

        $this->addFlash('success', $message);
        return $this->redirectToRoute('admin_users_edit', ['id' => $user->getId()]);
    }
}
