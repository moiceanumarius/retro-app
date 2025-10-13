<?php

namespace App\Command;

use App\Entity\User;
use App\Entity\Role;
use App\Entity\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-test-user',
    description: 'Creates a test user for E2E testing.',
)]
class CreateTestUserCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'test@example.com']);

        if (!$user) {
            $user = new User();
            $user->setEmail('test@example.com');
            $user->setFirstName('Test');
            $user->setLastName('User');
            $user->setCreatedAt(new \DateTimeImmutable());
            $user->setIsVerified(true);
            $this->entityManager->persist($user);
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, 'password123'));
        $this->entityManager->flush();

        // Assign MEMBER role
        $this->assignRoleToUser($user, 'ROLE_MEMBER', $io);

        $io->success('Test user created: test@example.com with password: password123');

        return Command::SUCCESS;
    }

    private function assignRoleToUser(User $user, string $roleCode, SymfonyStyle $io): void
    {
        $roleRepo = $this->entityManager->getRepository(Role::class);
        $role = $roleRepo->findOneBy(['code' => $roleCode]);

        if (!$role) {
            $io->warning("Role {$roleCode} not found in database. User will have no role assigned.");
            return;
        }

        // Remove existing roles
        $existingUserRoles = $this->entityManager->getRepository(UserRole::class)
            ->findBy(['user' => $user]);
        
        foreach ($existingUserRoles as $existingUserRole) {
            $this->entityManager->remove($existingUserRole);
        }

        $userRole = new UserRole();
        $userRole->setUser($user);
        $userRole->setRole($role);
        $userRole->setAssignedAt(new \DateTimeImmutable());
        $this->entityManager->persist($userRole);
        $this->entityManager->flush();

        $io->info("Assigned role: {$roleCode}");
    }
}