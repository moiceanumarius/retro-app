<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;

/**
 * UserProfileService
 * 
 * Service for managing user profiles
 * Handles profile updates, avatar uploads, and password changes
 */
class UserProfileService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private SluggerInterface $slugger,
        private string $avatarsDirectory
    ) {}

    /**
     * Update user profile
     */
    public function updateProfile(User $user, ?UploadedFile $avatarFile = null): void
    {
        if ($avatarFile) {
            $this->handleAvatarUpload($user, $avatarFile);
        }

        $user->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }

    /**
     * Handle avatar file upload
     */
    private function handleAvatarUpload(User $user, UploadedFile $avatarFile): void
    {
        $originalFilename = pathinfo($avatarFile->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = $this->slugger->slug($originalFilename);
        $newFilename = $safeFilename . '-' . uniqid() . '.' . $avatarFile->guessExtension();

        try {
            $avatarFile->move($this->avatarsDirectory, $newFilename);
            
            // Delete old avatar if exists
            if ($user->getAvatar()) {
                $oldAvatarPath = $this->avatarsDirectory . '/' . $user->getAvatar();
                if (file_exists($oldAvatarPath)) {
                    @unlink($oldAvatarPath);
                }
            }
            
            $user->setAvatar($newFilename);
        } catch (FileException $e) {
            throw new \RuntimeException('Error uploading avatar: ' . $e->getMessage());
        }
    }

    /**
     * Change user password
     */
    public function changePassword(User $user, string $currentPassword, string $newPassword): bool
    {
        // Verify current password
        if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
            return false;
        }

        // Hash and set new password
        $hashedPassword = $this->passwordHasher->hashPassword($user, $newPassword);
        $user->setPassword($hashedPassword);
        $user->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return true;
    }

    /**
     * Verify if password is correct
     */
    public function verifyPassword(User $user, string $password): bool
    {
        return $this->passwordHasher->isPasswordValid($user, $password);
    }

    /**
     * Delete user avatar
     */
    public function deleteAvatar(User $user): void
    {
        if ($user->getAvatar()) {
            $avatarPath = $this->avatarsDirectory . '/' . $user->getAvatar();
            if (file_exists($avatarPath)) {
                @unlink($avatarPath);
            }
            
            $user->setAvatar(null);
            $user->setUpdatedAt(new \DateTimeImmutable());
            
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        }
    }
}

