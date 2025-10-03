<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-rbac',
    description: 'Test RBAC system functionality',
)]
class TestRbacCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'User email to test')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = $input->getArgument('email');

        // Find user
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) {
            $io->error("User with email '{$email}' not found.");
            return Command::FAILURE;
        }

        $io->title("RBAC Test for User: {$user->getFullName()} ({$email})");

        // Test basic user info
        $io->section('User Information');
        $io->table(
            ['Property', 'Value'],
            [
                ['ID', $user->getId()],
                ['Full Name', $user->getFullName()],
                ['Email', $user->getEmail()],
                ['Created At', $user->getCreatedAt()->format('Y-m-d H:i:s')],
                ['Is Verified', $user->isVerified() ? 'Yes' : 'No'],
                ['Timezone', $user->getTimezone() ?? 'Not set'],
                ['Language', $user->getLanguage() ?? 'Not set'],
            ]
        );

        // Test roles
        $io->section('User Roles');
        $activeRoles = $user->getActiveRoles();
        if (empty($activeRoles)) {
            $io->warning('User has no active roles assigned.');
        } else {
            $io->table(
                ['Role Code'],
                array_map(fn($role) => [$role], $activeRoles)
            );
        }

        // Test role checks
        $io->section('Role Checks');
        $testRoles = ['ROLE_ADMIN', 'ROLE_FACILITATOR', 'ROLE_MEMBER', 'ROLE_SUPERVISOR'];
        $roleChecks = [];
        foreach ($testRoles as $role) {
            $hasRole = $user->hasRole($role);
            $roleChecks[] = [$role, $hasRole ? '✓ Yes' : '✗ No'];
        }
        $io->table(['Role', 'Has Role'], $roleChecks);

        // Test multiple role check
        $io->section('Multiple Role Checks');
        $multipleChecks = [
            ['Admin or Facilitator', $user->hasAnyRole(['ROLE_ADMIN', 'ROLE_FACILITATOR']) ? '✓ Yes' : '✗ No'],
            ['Member or Supervisor', $user->hasAnyRole(['ROLE_MEMBER', 'ROLE_SUPERVISOR']) ? '✓ Yes' : '✗ No'],
            ['Admin and Member', $user->hasRole('ROLE_ADMIN') && $user->hasRole('ROLE_MEMBER') ? '✓ Yes' : '✗ No'],
        ];
        $io->table(['Check', 'Result'], $multipleChecks);

        // Test UserRole entities
        $io->section('UserRole Details');
        $userRoles = $user->getUserRoles();
        if ($userRoles->isEmpty()) {
            $io->warning('No UserRole entities found.');
        } else {
            $userRoleData = [];
            foreach ($userRoles as $userRole) {
                $userRoleData[] = [
                    $userRole->getRole()->getName(),
                    $userRole->getRole()->getCode(),
                    $userRole->getAssignedAt()->format('Y-m-d H:i:s'),
                    $userRole->isActive() ? 'Active' : 'Inactive',
                    $userRole->isExpired() ? 'Expired' : 'Valid',
                    $userRole->getAssignedBy() ?? 'Unknown'
                ];
            }
            $io->table(
                ['Role Name', 'Role Code', 'Assigned At', 'Status', 'Expiry', 'Assigned By'],
                $userRoleData
            );
        }

        $io->success('RBAC test completed successfully!');
        return Command::SUCCESS;
    }
}
