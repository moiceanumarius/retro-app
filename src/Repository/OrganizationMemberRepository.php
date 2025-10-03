<?php

namespace App\Repository;

use App\Entity\Organization;
use App\Entity\OrganizationMember;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pentru entitatea OrganizationMember
 * 
 * Contine metode pentru operațiile complexe de bază de date legate de membrii organizațiilor.
 * Extinde ServiceEntityRepository pentru funcționalități CRUD de bază și adaugă 
 * metode specializate pentru gestionarea relațiilor User-Organization.
 * 
 * Funcționalități principale:
 * - Găsirea membrilor activi/inactivi ai unei organizații
 * - Căutarea organizațiilor din care face parte un user
 * - Verificări de unicitate și validări
 * - Statistici despre membership
 */
class OrganizationMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrganizationMember::class);
    }

    /**
     * Returnează membrii activi ai unei organizații
     * 
     * @param Organization $organization Organizația pentru care să căutăm membrii
     * @return OrganizationMember[] Lista membrilor activi
     */
    public function findActiveByOrganization(Organization $organization): array
    {
        return $this->createQueryBuilder('om')
            ->where('om.organization = :organization')
            ->andWhere('om.isActive = :active')
            ->andWhere('om.expiresAt IS NULL OR om.expiresAt > :now')
            ->andWhere('om.leftAt IS NULL')
            ->setParameter('organization', $organization)
            ->setParameter('active', true)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('om.joinedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returnează toate membrăile unei organizații (active și inactive)
     * Pentru admin și debugging
     * 
     * @param Organization $organization Organizația pentru care să căutăm membrii
     * @return OrganizationMember[] Lista tuturor membrilor
     */
    public function findAllByOrganization(Organization $organization): array
    {
        return $this->createQueryBuilder('om')
            ->where('om.organization = :organization')
            ->setParameter('organization', $organization)
            ->orderBy('om.joinedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returnează organizațiile din care face parte un user
     * Utile pentru a afișa organizațiile utilizatorului curent
     * 
     * @param User $user Userul pentru care să căutăm organizațiile
     * @return OrganizationMember[] Lista membrilor organizațiilor
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('om')
            ->where('om.user = :user')
            ->andWhere('om.isActive = :active')
            ->andWhere('om.expiresAt IS NULL OR om.expiresAt > :now')
            ->andWhere('om.leftAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('active', true)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('om.joinedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Verifică dacă un user este membru al unei organizații
     * 
     * @param User $user Userul de verificat
     * @param Organization $organization Organizația de verificat
     * @return bool True dacă userul este membru activ
     */
    public function isUserMemberOfOrganization(User $user, Organization $organization): bool
    {
        $result = $this->createQueryBuilder('om')
            ->select('COUNT(om.id)')
            ->where('om.user = :user')
            ->andWhere('om.organization = :organization')
            ->andWhere('om.isActive = :active')
            ->andWhere('om.expiresAt IS NULL OR om.expiresAt > :now')
            ->andWhere('om.leftAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('organization', $organization)
            ->setParameter('active', true)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getSingleScalarResult();

        return $result > 0;
    }

    /**
     * Găsește membrul unei organizații pentru un user specific
     * 
     * @param User $user Userul căutat
     * @param Organization $organization Organizația căutată
     * @return OrganizationMember|null Membrii găsit sau null
     */
    public function findMemberByUserAndOrganization(User $user, Organization $organization): ?OrganizationMember
    {
        return $this->createQueryBuilder('om')
            ->where('om.user = :user')
            ->andWhere('om.organization = :organization')
            ->andWhere('om.isActive = :active')
            ->setParameter('user', $user)
            ->setParameter('organization', $organization)
            ->setParameter('active', true)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Returnează membrii care au roluri mai mari decât MEMBER
     * Pentru asignarea la organizații
     * 
     * @param Organization $organization Organizația pentru care să căutăm
     * @return OrganizationMember[] Lista membrilor cu roluri înalte
     */
    public function findHigherThanMemberByOrganization(Organization $organization): array
    {
        // Căutăm userii care au roluri ROLE_FACILITATOR sau mai înalte
        return $this->createQueryBuilder('om')
            ->join('om.user', 'u')
            ->join('u.userRoles', 'ur')
            ->join('ur.role', 'r')
            ->where('om.organization = :organization')
            ->andWhere('om.isActive = :active')
            ->andWhere('ur.isActive = :userRoleActive')
            ->andWhere('om.expiresAt IS NULL OR om.expiresAt > :now')
            ->andWhere('om.leftAt IS NULL')
            ->andWhere('r.code IN (:roles)')
            ->setParameter('organization', $organization)
            ->setParameter('active', true)
            ->setParameter('userRoleActive', true)
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('roles', ['ROLE_FACILITATOR', 'ROLE_TEAM_LEAD', 'ROLE_ADMIN'])
            ->orderBy('om.joinedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returnează membrii expirați (care trebuie să fie reactivați sau eliminați)
     * 
     * @param Organization|null $organization Organizația specifică sau null pentru toate
     * @return OrganizationMember[] Lista membrilor expirați
     */
    public function findExpired(?Organization $organization = null): array
    {
        $qb = $this->createQueryBuilder('om')
            ->where('om.isActive = :active')
            ->andWhere('om.expiresAt IS NOT NULL')
            ->andWhere('om.expiresAt < :now')
            ->andWhere('om.leftAt IS NULL')
            ->setParameter('active', true)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('om.expiresAt', 'ASC');

        if ($organization) {
            $qb->andWhere('om.organization = :organization')
               ->setParameter('organization', $organization);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Marchează un membru ca lăsat organizația
     * 
     * @param OrganizationMember $member Membrii de marcat ca lăsat
     */
    public function markAsLeft(OrganizationMember $member): void
    {
        $member->leaveOrganization();
        $this->getEntityManager()->persist($member);
        $this->getEntityManager()->flush();
    }

    /**
     * Reactivează un membru în organizație
     * 
     * @param OrganizationMember $member Membrii de reactivat
     */
    public function reactivateMember(OrganizationMember $member): void
    {
        $member->rejoinOrganization();
        $this->getEntityManager()->persist($member);
        $this->getEntityManager()->flush();
    }

    /**
     * Adaugă un user ca membru în organizație
     * 
     * @param User $user Userul de adăugat
     * @param Organization $organization Organizația unde să adaug
     * @param User|null $invitedBy Userul care face invitația
     * @param string|null $role Rolul membrului
     * @return OrganizationMember Membrul creat
     */
    public function addUserToOrganization(User $user, Organization $organization, ?User $invitedBy = null, ?string $role = null): OrganizationMember
    {
        $member = new OrganizationMember();
        $member->setUser($user);
        $member->setOrganization($organization);
        $member->setInvitedBy($invitedBy);
        $member->setRole($role);
        
        $this->getEntityManager()->persist($member);
        $this->getEntityManager()->flush();
        
        return $member;
    }

    /**
     * Returnează statistici pentru member-ul unei organizații
     * 
     * @param Organization $organization Organizația pentru statistici
     * @return array Array cu statistici detaliate
     */
    public function getOrganizationStatistics(Organization $organization): array
    {
        // Active count query
        $activeCount = $this->createQueryBuilder('om')
            ->select('COUNT(om.id)')
            ->where('om.organization = :organization')
            ->andWhere('om.isActive = :active')
            ->andWhere('om.expiresAt IS NULL OR om.expiresAt > :now')
            ->andWhere('om.leftAt IS NULL')
            ->setParameter('organization', $organization)
            ->setParameter('active', true)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getSingleScalarResult();

        // Total count query
        $totalCount = $this->createQueryBuilder('om')
            ->select('COUNT(om.id)')
            ->where('om.organization = :organization')
            ->setParameter('organization', $organization)
            ->getQuery()
            ->getSingleScalarResult();

        // Expired count query
        $expiredCount = $this->createQueryBuilder('om')
            ->select('COUNT(om.id)')
            ->where('om.organization = :organization')
            ->andWhere('om.isActive = :active')
            ->andWhere('om.expiresAt IS NOT NULL')
            ->andWhere('om.expiresAt < :now')
            ->setParameter('organization', $organization)
            ->setParameter('active', true)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'active_members' => $activeCount,
            'total_members' => $totalCount,
            'expired_members' => $expiredCount,
            'inactive_members' => $totalCount - $activeCount - $expiredCount,
        ];
    }

    /**
     * Găsește membrii care trebuie să fie eliminați automat (expirați de mai mult de X zile)
     * 
     * @param int $daysAfterExpiry Zilele după expirare pentru eliminarea automată
     * @return OrganizationMember[] Membrii care trebuie eliminați automat
     */
    public function findAutoRemovableMembers(int $daysAfterExpiry = 30): array
    {
        $cutoffDate = new \DateTimeImmutable("-{$daysAfterExpiry} days");
        
        return $this->createQueryBuilder('om')
            ->where('om.isActive = :active')
            ->andWhere('om.expiresAt IS NOT NULL')
            ->andWhere('om.expiresAt < :cutoff')
            ->andWhere('om.leftAt IS NULL')
            ->setParameter('active', true)
            ->setParameter('cutoff', $cutoffDate)
            ->getQuery()
            ->getResult();
    }
}
