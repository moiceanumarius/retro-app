<?php

namespace App\Repository;

use App\Entity\Vote;
use App\Entity\User;
use App\Entity\Retrospective;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class VoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Vote::class);
    }

    /**
     * Get all votes for a user in a specific retrospective
     */
    public function findByUserAndRetrospective(User $user, Retrospective $retrospective): array
    {
        return $this->createQueryBuilder('v')
            ->innerJoin('v.retrospectiveItem', 'ri')
            ->where('v.user = :user')
            ->andWhere('ri.retrospective = :retrospective')
            ->setParameter('user', $user)
            ->setParameter('retrospective', $retrospective)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get total vote count for a user in a retrospective
     */
    public function getTotalVoteCount(User $user, Retrospective $retrospective): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('SUM(v.voteCount)')
            ->innerJoin('v.retrospectiveItem', 'ri')
            ->where('v.user = :user')
            ->andWhere('ri.retrospective = :retrospective')
            ->setParameter('user', $user)
            ->setParameter('retrospective', $retrospective)
            ->getQuery()
            ->getSingleScalarResult();
    }
}

