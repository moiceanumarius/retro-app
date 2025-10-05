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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-user',
    description: 'Create a new user with optional role assignment',
)]
class CreateUserCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'User email')
            ->addArgument('password', InputArgument::REQUIRED, 'User password')
            ->addArgument('first_name', InputArgument::REQUIRED, 'User first name')
            ->addArgument('last_name', InputArgument::REQUIRED, 'User last name')
            ->addOption('role', 'r', InputOption::VALUE_OPTIONAL, 'Role code to assign (e.g., ROLE_ADMIN)')
            ->addOption('verified', null, InputOption::VALUE_NONE, 'Mark user as verified')
            ->addOption('timezone', 't', InputOption::VALUE_OPTIONAL, 'User timezone', 'Europe/Bucharest')
            ->addOption('language', 'l', InputOption::VALUE_OPTIONAL, 'User language', 'en')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $email = $input->getArgument('email');
        $password = $input->getArgument('password');
        $firstName = $input->getArgument('first_name');
        $lastName = $input->getArgument('last_name');
        $roleCode = $input->getOption('role');
        $isVerified = $input->getOption('verified');
        $timezone = $input->getOption('timezone');
        $language = $input->getOption('language');

        // Check if user already exists
        $existingUser = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existingUser) {
            $io->error("User with email '{$email}' already exists.");
            return Command::FAILURE;
        }

        // Create user
        $user = new User();
        $user->setEmail($email);
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $user->setCreatedAt(new \DateTimeImmutable());
        $user->setUpdatedAt(new \DateTimeImmutable());
        $user->setIsVerified($isVerified);
        $user->setTimezone($timezone);
        $user->setLanguage($language);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success("User '{$email}' created successfully.");

        // Assign role if specified
        if ($roleCode) {
            $role = $this->entityManager->getRepository(Role::class)->findOneBy(['code' => $roleCode]);
            if (!$role) {
                $io->warning("Role with code '{$roleCode}' not found. User created without role.");
                return Command::SUCCESS;
            }

            // Check if user already has this role
            $existingUserRole = $this->entityManager->getRepository(UserRole::class)->findOneBy([
                'user' => $user,
                'role' => $role
            ]);

            if ($existingUserRole) {
                $io->warning("User already has role '{$roleCode}'.");
            } else {
                $userRole = new UserRole();
                $userRole->setUser($user);
                $userRole->setRole($role);
                $userRole->setAssignedAt(new \DateTimeImmutable());
                $userRole->setAssignedBy('system');

                $this->entityManager->persist($userRole);
                $this->entityManager->flush();

                $io->success("Role '{$roleCode}' assigned to user '{$email}' successfully.");
            }
        }

        return Command::SUCCESS;
    }
}
