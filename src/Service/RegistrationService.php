<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * RegistrationService
 * 
 * Service for user registration
 * Handles user creation, password hashing, and initial setup
 */
class RegistrationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher
    ) {}

    /**
     * Register new user
     */
    public function registerUser(User $user, string $plainPassword): User
    {
        // Hash password
        $hashedPassword = $this->passwordHasher->hashPassword($user, $plainPassword);
        $user->setPassword($hashedPassword);

        // Set timestamps
        $user->setCreatedAt(new \DateTimeImmutable());
        $user->setUpdatedAt(new \DateTimeImmutable());

        // Set user as verified automatically (no email verification required)
        $user->setIsVerified(true);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * Validate if email is available
     */
    public function isEmailAvailable(string $email): bool
    {
        $existingUser = $this->entityManager
            ->getRepository(User::class)
            ->findOneBy(['email' => $email]);

        return $existingUser === null;
    }

    /**
     * Validate if username is available
     */
    public function isUsernameAvailable(string $username): bool
    {
        $existingUser = $this->entityManager
            ->getRepository(User::class)
            ->findOneBy(['username' => $username]);

        return $existingUser === null;
    }
}

