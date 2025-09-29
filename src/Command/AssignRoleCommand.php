<?php

namespace App\Command;

use App\Entity\Role;
use App\Entity\User;
use App\Entity\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:assign-role',
    description: 'Assign a role to a user',
)]
class AssignRoleCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'User email')
            ->addArgument('role', InputArgument::REQUIRED, 'Role code (e.g., ROLE_ADMIN)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = $input->getArgument('email');
        $roleCode = $input->getArgument('role');

        // Find user
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) {
            $io->error("User with email '{$email}' not found.");
            return Command::FAILURE;
        }

        // Find role
        $role = $this->entityManager->getRepository(Role::class)->findOneBy(['code' => $roleCode]);
        if (!$role) {
            $io->error("Role with code '{$roleCode}' not found.");
            return Command::FAILURE;
        }

        // Check if user already has this role
        $existingUserRole = $this->entityManager->getRepository(UserRole::class)->findOneBy([
            'user' => $user,
            'role' => $role
        ]);

        if ($existingUserRole) {
            // Update existing role assignment
            $existingUserRole->setAssignedAt(new \DateTimeImmutable());
            $existingUserRole->setAssignedBy('system');
            $existingUserRole->setIsActive(true);
            
            $this->entityManager->persist($existingUserRole);
            $this->entityManager->flush();
            
            $io->success("Role '{$roleCode}' updated for user '{$email}' successfully.");
        } else {
            // Create new UserRole
            $userRole = new UserRole();
            $userRole->setUser($user);
            $userRole->setRole($role);
            $userRole->setAssignedAt(new \DateTimeImmutable());
            $userRole->setAssignedBy('system');

            $this->entityManager->persist($userRole);
            $this->entityManager->flush();

            $io->success("Role '{$roleCode}' assigned to user '{$email}' successfully.");
        }

        return Command::SUCCESS;
    }
}
