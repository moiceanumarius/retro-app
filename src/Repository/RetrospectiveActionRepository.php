<?php

namespace App\Repository;

use App\Entity\RetrospectiveAction;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RetrospectiveAction>
 */
class RetrospectiveActionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RetrospectiveAction::class);
    }

    //    /**
    //     * @return RetrospectiveAction[] Returns an array of RetrospectiveAction objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('r')
    //            ->andWhere('r.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('r.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    /**
     * Find actions for team leads and facilitators
     * Returns actions from retrospectives where user is facilitator or team lead
     */
    public function findByTeamLeadOrFacilitator(User $user): array
    {
        return $this->createQueryBuilder('ra')
            ->leftJoin('ra.retrospective', 'r')
            ->leftJoin('r.team', 't')
            ->where('r.facilitator = :user')
            ->orWhere('t.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('ra.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find actions due soon (within next 7 days)
     */
    public function findByDueDateSoon(\DateTime $startDate, \DateTime $endDate): array
    {
        return $this->createQueryBuilder('ra')
            ->where('ra.dueDate BETWEEN :start AND :end')
            ->andWhere('ra.status NOT IN (:completedStates)')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->setParameter('completedStates', ['completed', 'cancelled'])
            ->orderBy('ra.dueDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find overdue actions
     */
    public function findByOverdue(): array
    {
        return $this->createQueryBuilder('ra')
            ->where('ra.dueDate < :now')
            ->andWhere('ra.status NOT IN (:completedStates)')
            ->setParameter('now', new \DateTime())
            ->setParameter('completedStates', ['completed', 'cancelled'])
            ->orderBy('ra.dueDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find actions by status
     */
    public function findByStatus(string $status): array
    {
        return $this->createQueryBuilder('ra')
            ->where('ra.status = :status')
            ->setParameter('status', $status)
            ->orderBy('ra.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByTeam(\App\Entity\Team $team): array
    {
        return $this->createQueryBuilder('ra')
            ->leftJoin('ra.retrospective', 'r')
            ->where('r.team = :team')
            ->setParameter('team', $team)
            ->orderBy('ra.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByTeamAndStatus(\App\Entity\Team $team, string $status): array
    {
        return $this->createQueryBuilder('ra')
            ->leftJoin('ra.retrospective', 'r')
            ->where('ra.status = :status')
            ->andWhere('r.team = :team')
            ->setParameter('status', $status)
            ->setParameter('team', $team)
            ->orderBy('ra.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
