<?php

namespace App\Admin\Command;

use App\Admin\Entity\AdminUser;
use App\Admin\Repository\AdminUserRepository;
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
    name: 'app:create-admin-user',
    description: 'Create a new admin user',
)]
class CreateAdminUserCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private AdminUserRepository $adminUserRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Admin email')
            ->addArgument('password', InputArgument::REQUIRED, 'Admin password')
            ->addArgument('firstName', InputArgument::REQUIRED, 'First name')
            ->addArgument('lastName', InputArgument::REQUIRED, 'Last name')
            ->addOption('super-admin', null, InputOption::VALUE_NONE, 'Create super admin with all permissions');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $email = $input->getArgument('email');
        $password = $input->getArgument('password');
        $firstName = $input->getArgument('firstName');
        $lastName = $input->getArgument('lastName');
        $isSuperAdmin = $input->getOption('super-admin');

        // Check if admin already exists
        if ($this->adminUserRepository->findOneByEmail($email)) {
            $io->error('Admin user with email ' . $email . ' already exists!');
            return Command::FAILURE;
        }

        // Create admin user
        $adminUser = new AdminUser();
        $adminUser->setEmail($email);
        $adminUser->setFirstName($firstName);
        $adminUser->setLastName($lastName);
        $adminUser->setPassword($this->passwordHasher->hashPassword($adminUser, $password));
        
        if ($isSuperAdmin) {
            $adminUser->setRoles(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        } else {
            $adminUser->setRoles(['ROLE_ADMIN']);
        }

        $this->entityManager->persist($adminUser);
        $this->entityManager->flush();

        $io->success('Admin user created successfully!');
        $io->table(
            ['Field', 'Value'],
            [
                ['Email', $adminUser->getEmail()],
                ['Name', $adminUser->getFullName()],
                ['Roles', implode(', ', $adminUser->getRoles())],
                ['Active', $adminUser->isActive() ? 'Yes' : 'No'],
            ]
        );

        return Command::SUCCESS;
    }
}
