<?php

namespace App\Repository;

use App\Entity\Organization;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pentru entitatea Organization
 * 
 * Conține metode pentru operațiile complexe de bază de date legate de organizații.
 * Estende ServiceEntityRepository pentru funcționalități CRUD de bază și adaugă 
 * metode specializate pentru necesitățile aplicației RetroApp.
 * 
 * Funcționalități principale:
 * - Găsirea organizațiilor active/inactive
 * - Căutarea organizațiilor deținute de un user specific
 * - Listarea organizațiilor cu membri activi
 * - Statistici și aggregate pentru dashboard
 */
class OrganizationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Organization::class);
    }

    /**
     * Returnează toate organizațiile active
     * 
     * @return Organization[] Lista organizațiilor active
     */
    public function findActiveOrganizations(): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('o.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returnează toate organizațiile (active și inactive)
     * Pentru admini care pot gestiona organizațiile inactive
     * 
     * @return Organization[] Lista tuturor organizațiilor
     */
    public function findAllOrganizations(): array
    {
        return $this->createQueryBuilder('o')
            ->orderBy('o.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returnează organizațiile deținute de un user specific
     * Utile pentru a afișa organizațiile create de un admin
     * 
     * @param User $owner Userul pentru care să căutăm organizațiile
     * @return Organization[] Lista organizațiilor deținute
     */
    public function findByOwner(User $owner): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('o.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returnează organizațiile active deținute de un user specific
     * 
     * @param User $owner Userul pentru care să căutăm organizațiile active
     * @return Organization[] Lista organizațiilor active deținute
     */
    public function findActiveByOwner(User $owner): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.owner = :owner')
            ->andWhere('o.isActive = :active')
            ->setParameter('owner', $owner)
            ->setParameter('active', true)
            ->orderBy('o.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Caută organizații după nume (fuzzy search)
     * 
     * @param string $searchTerm Termenul de căutare
     * @return Organization[] Lista organizațiilor găsite
     */
    public function searchByName(string $searchTerm): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.isActive = :active')
            ->andWhere('o.name LIKE :searchTerm')
            ->setParameter('active', true)
            ->setParameter('searchTerm', '%' . $searchTerm . '%')
            ->orderBy('o.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returnează organizațiile cu cel mai mare număr de membri
     * Utile pentru statistici și dashboard-ul admin
     * 
     * @param int $limit Limita numărului de organizații returnate
     * @return Organization[] Lista organizațiilor ordonate după numărul de membri
     */
    public function findMostPopular(int $limit = 10): array
    {
        return $this->createQueryBuilder('o')
            ->select('o', 'COUNT(om.id) as memberCount')
            ->leftJoin('o.organizationMembers', 'om')
            ->where('o.isActive = :active')
            ->andWhere('om.isActive = :memberActive')
            ->setParameter('active', true)
            ->setParameter('memberActive', true)
            ->groupBy('o.id')
            ->orderBy('memberCount', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Returnează organizațiile create recent
     * 
     * @param int $limit Limita numărului de organizații returnate
     * @return Organization[] Lista organizațiilor ordonate după data creării
     */
    public function findRecent(int $limit = 10): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('o.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Returnează numărul total de organizații (active și inactive)
     * 
     * @return int Numărul total de organizații
     */
    public function getTotalCount(): int
    {
        return $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Returnează numărul de organizații active
     * 
     * @return int Numărul de organizații active
     */
    public function getActiveCount(): int
    {
        return $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->where('o.isActive = :active')
            ->setParameter('active', true)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Returnează statisticile pentru organizații
     * 
     * @return array Array cu statistici detaliate
     */
    public function getStatistics(): array
    {
        $qb = $this->createQueryBuilder('o');
        
        $totalCount = $qb
            ->select('COUNT(o.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $activeCount = $qb
            ->select('COUNT(o.id)')
            ->where('o.isActive = :active')
            ->setParameter('active', true)
            ->getQuery()
            ->getSingleScalarResult();

        $totalMembers = $this->getEntityManager()
            ->getRepository($this->getEntityName())
            ->createQueryBuilder('o')
            ->select('SUM(COALESCE(SIZE(o.organizationMembers), 0))')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'total_organizations' => $totalCount,
            'active_organizations' => $activeCount,
            'inactive_organizations' => $totalCount - $activeCount,
            'total_members' => $totalMembers,
            'avg_members_per_org' => $activeCount > 0 ? round($totalMembers / $activeCount, 2) : 0,
        ];
    }

    /**
     * Șterge logical organizația (marchează ca inactivă)
     * 
     * @param Organization $organization Organizația de șters
     */
    public function softDelete(Organization $organization): void
    {
        $organization->setIsActive(false);
        $this->getEntityManager()->persist($organization);
        $this->getEntityManager()->flush();
    }

    /**
     * Reactivează organizația
     * 
     * @param Organization $organization Organizația de reactivat
     */
    public function reactivate(Organization $organization): void
    {
        $organization->setIsActive(true);
        $this->getEntityManager()->persist($organization);
        $this->getEntityManager()->flush();
    }
}
