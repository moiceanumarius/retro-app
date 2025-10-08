<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * OrganizationService
 * 
 * Service for organization-related operations
 * Handles organization membership and access checks
 */
class OrganizationService
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    /**
     * Check if user has an organization (as member or owner)
     */
    public function userHasOrganization(User $user): bool
    {
        // Check if user is a member of any organization
        $organizationMember = $this->entityManager
            ->getRepository(\App\Entity\OrganizationMember::class)
            ->findOneBy(['user' => $user, 'isActive' => true]);
            
        if ($organizationMember !== null) {
            return true;
        }
        
        // Check if user owns any organization
        $ownedOrganizations = $user->getOwnedOrganizations();
        return !$ownedOrganizations->isEmpty();
    }

    /**
     * Get user's organization (as member or owner)
     */
    public function getUserOrganization(User $user): ?\App\Entity\Organization
    {
        // First check if user is a member of any organization
        foreach ($user->getOrganizationMemberships() as $membership) {
            if ($membership->isActive() && $membership->getLeftAt() === null) {
                return $membership->getOrganization();
            }
        }
        
        // If not a member, check if user owns any organization
        $ownedOrganizations = $user->getOwnedOrganizations();
        if (!$ownedOrganizations->isEmpty()) {
            return $ownedOrganizations->first();
        }

        return null;
    }

    /**
     * Get teams for user within their organization
     */
    public function getUserTeamsInOrganization(User $user): array
    {
        $userOrganization = $this->getUserOrganization($user);

        // If user has no organization, return empty array
        if (!$userOrganization) {
            return [];
        }

        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('t')
           ->from(\App\Entity\Team::class, 't')
           ->leftJoin('t.teamMembers', 'tm')
           ->leftJoin('tm.user', 'u')
           ->where('(t.owner = :user OR u = :user)')
           ->andWhere('t.organization = :organization')
           ->andWhere('t.isActive = :active')
           ->setParameter('user', $user)
           ->setParameter('organization', $userOrganization)
           ->setParameter('active', true)
           ->orderBy('t.name', 'ASC');

        return $qb->getQuery()->getResult();
    }

    /**
     * Check if user can access organization
     */
    public function userCanAccessOrganization(User $user, int $organizationId): bool
    {
        $organization = $this->entityManager
            ->getRepository(\App\Entity\Organization::class)
            ->find($organizationId);

        if (!$organization) {
            return false;
        }

        // Check if user is owner
        if ($organization->getOwner()->getId() === $user->getId()) {
            return true;
        }

        // Check if user is active member
        foreach ($user->getOrganizationMemberships() as $membership) {
            if ($membership->getOrganization()->getId() === $organizationId && 
                $membership->isActive() && 
                $membership->getLeftAt() === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all organizations user has access to
     */
    public function getUserOrganizations(User $user): array
    {
        $organizations = [];

        // Add owned organizations
        foreach ($user->getOwnedOrganizations() as $org) {
            $organizations[$org->getId()] = $org;
        }

        // Add organizations where user is member
        foreach ($user->getOrganizationMemberships() as $membership) {
            if ($membership->isActive() && $membership->getLeftAt() === null) {
                $org = $membership->getOrganization();
                $organizations[$org->getId()] = $org;
            }
        }

        return array_values($organizations);
    }
}

