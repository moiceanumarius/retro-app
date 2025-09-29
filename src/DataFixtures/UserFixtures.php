<?php

namespace App\DataFixtures;

use App\Entity\Role;
use App\Entity\User;
use App\Entity\UserRole;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserFixtures extends Fixture
{
    public function __construct(
        private UserPasswordHasherInterface $passwordHasher
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        // Get roles
        $adminRole = $manager->getRepository(Role::class)->findOneBy(['code' => 'ROLE_ADMIN']);
        $facilitatorRole = $manager->getRepository(Role::class)->findOneBy(['code' => 'ROLE_FACILITATOR']);
        $memberRole = $manager->getRepository(Role::class)->findOneBy(['code' => 'ROLE_MEMBER']);

        // Create admin user
        $admin = new User();
        $admin->setEmail('admin@retroapp.com');
        $admin->setFirstName('Admin');
        $admin->setLastName('User');
        $admin->setPassword($this->passwordHasher->hashPassword($admin, 'password123'));
        $admin->setCreatedAt(new \DateTimeImmutable());
        $admin->setUpdatedAt(new \DateTimeImmutable());
        $admin->setIsVerified(true);
        $admin->setTimezone('Europe/Bucharest');
        $admin->setLanguage('en');

        $manager->persist($admin);

        // Create facilitator user
        $facilitator = new User();
        $facilitator->setEmail('facilitator@retroapp.com');
        $facilitator->setFirstName('John');
        $facilitator->setLastName('Facilitator');
        $facilitator->setPassword($this->passwordHasher->hashPassword($facilitator, 'password123'));
        $facilitator->setCreatedAt(new \DateTimeImmutable());
        $facilitator->setUpdatedAt(new \DateTimeImmutable());
        $facilitator->setIsVerified(true);
        $facilitator->setTimezone('Europe/Bucharest');
        $facilitator->setLanguage('en');

        $manager->persist($facilitator);

        // Create member user
        $member = new User();
        $member->setEmail('member@retroapp.com');
        $member->setFirstName('Jane');
        $member->setLastName('Member');
        $member->setPassword($this->passwordHasher->hashPassword($member, 'password123'));
        $member->setCreatedAt(new \DateTimeImmutable());
        $member->setUpdatedAt(new \DateTimeImmutable());
        $member->setIsVerified(true);
        $member->setTimezone('Europe/Bucharest');
        $member->setLanguage('en');

        $manager->persist($member);

        $manager->flush();

        // Assign roles
        if ($adminRole) {
            $adminUserRole = new UserRole();
            $adminUserRole->setUser($admin);
            $adminUserRole->setRole($adminRole);
            $adminUserRole->setAssignedAt(new \DateTimeImmutable());
            $adminUserRole->setAssignedBy('system');
            $manager->persist($adminUserRole);
        }

        if ($facilitatorRole) {
            $facilitatorUserRole = new UserRole();
            $facilitatorUserRole->setUser($facilitator);
            $facilitatorUserRole->setRole($facilitatorRole);
            $facilitatorUserRole->setAssignedAt(new \DateTimeImmutable());
            $facilitatorUserRole->setAssignedBy('system');
            $manager->persist($facilitatorUserRole);
        }

        if ($memberRole) {
            $memberUserRole = new UserRole();
            $memberUserRole->setUser($member);
            $memberUserRole->setRole($memberRole);
            $memberUserRole->setAssignedAt(new \DateTimeImmutable());
            $memberUserRole->setAssignedBy('system');
            $manager->persist($memberUserRole);
        }

        $manager->flush();

        // Add references
        $this->addReference('user_admin', $admin);
        $this->addReference('user_facilitator', $facilitator);
        $this->addReference('user_member', $member);
    }
}
