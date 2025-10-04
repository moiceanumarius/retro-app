<?php

namespace App\Repository;

use App\Entity\TimerLike;
use App\Entity\User;
use App\Entity\Retrospective;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TimerLike>
 */
class TimerLikeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TimerLike::class);
    }

    public function findByUserAndRetrospective(User $user, Retrospective $retrospective): ?TimerLike
    {
        return $this->findOneBy([
            'user' => $user,
            'retrospective' => $retrospective
        ]);
    }

    public function findByRetrospective(Retrospective $retrospective): array
    {
        return $this->findBy([
            'retrospective' => $retrospective,
            'isLiked' => true
        ]);
    }

    public function save(TimerLike $timerLike): void
    {
        $this->getEntityManager()->persist($timerLike);
        $this->getEntityManager()->flush();
    }

    public function remove(TimerLike $timerLike): void
    {
        $this->getEntityManager()->remove($timerLike);
        $this->getEntityManager()->flush();
    }
}
