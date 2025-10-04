<?php

namespace App\Repository;

use App\Entity\RetrospectiveAction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pentru statistici acțiuni retrospective
 * 
 * Oferă metode specializate pentru calcularea statisticilor
 * acțiunilor pentru dashboard și rapoarte
 */
class ActionStatisticsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RetrospectiveAction::class);
    }

    /**
     * Returnează statistici de bază pentru acțiuni
     * 
     * @param array $teamIds ID-urile echipelor pentru care se calculează statisticile
     * @return array Statistici detaliate
     */
    public function getActionStatistics(array $teamIds = []): array
    {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.retrospective', 'r');
        
        if (!empty($teamIds)) {
            $qb->where('r.team IN (:teamIds)')
               ->setParameter('teamIds', $teamIds);
        }

        $total = $qb->select('COUNT(a.id)')
                   ->getQuery()
                   ->getSingleScalarResult();

        // Statistici pe status
        $statusStats = $this->createQueryBuilder('a')
            ->select('a.status, COUNT(a.id) as count')
            ->leftJoin('a.retrospective', 'r')
            ->groupBy('a.status');
            
        if (!empty($teamIds)) {
            $statusStats->where('r.team IN (:teamIds)')
                       ->setParameter('teamIds', $teamIds);
        }

        $statusResults = $statusStats->getQuery()->getResult();
        
        $statusCounts = [];
        foreach ($statusResults as $result) {
            $statusCounts[$result['status']] = (int) $result['count'];
        }

        // Rate de completare
        $completionRate = $total > 0 ? 
            round(($statusCounts['completed'] ?? 0) / $total * 100, 1) : 0;

        // Acțiuni overdue
        $overdueCount = $this->countOverdueActions($teamIds);

        // Timpul mediu de completare
        $avgCompletionTime = $this->calculateAverageCompletionTime($teamIds);

        return [
            'total_actions' => (int) $total,
            'pending_actions' => $statusCounts['pending'] ?? 0,
            'in_progress_actions' => $statusCounts['in_progress'] ?? 0,
            'completed_actions' => $statusCounts['completed'] ?? 0,
            'cancelled_actions' => $statusCounts['cancelled'] ?? 0,
            'overdue_actions' => $overdueCount,
            'completion_rate' => $completionRate,
            'average_completion_time_days' => $avgCompletionTime,
            'average_completion_time_formatted' => $this->formatCompletionTime($avgCompletionTime)
        ];
    }

    /**
     * Numără acțiunile overdue (cu deadline depășit)
     * 
     * @param array $teamIds
     * @return int
     */
    private function countOverdueActions(array $teamIds = []): int
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->leftJoin('a.retrospective', 'r')
            ->where('a.dueDate < :now')
            ->andWhere('a.status NOT IN (:completedStatuses)')
            ->setParameter('now', new \DateTime())
            ->setParameter('completedStatuses', ['completed', 'cancelled']);

        if (!empty($teamIds)) {
            $qb->andWhere('r.team IN (:teamIds)')
               ->setParameter('teamIds', $teamIds);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Calculează timpul mediu de completare al acțiunilor
     * 
     * @param array $teamIds
     * @return float Timpul în zile
     */
    private function calculateAverageCompletionTime(array $teamIds = []): float
    {
        $qb = $this->createQueryBuilder('a')
            ->select('a.createdAt, a.completedAt')
            ->leftJoin('a.retrospective', 'r')
            ->where('a.status = :completed')
            ->andWhere('a.completedAt IS NOT NULL')
            ->setParameter('completed', 'completed');

        if (!empty($teamIds)) {
            $qb->andWhere('r.team IN (:teamIds)')
               ->setParameter('teamIds', $teamIds);
        }

        $results = $qb->getQuery()->getResult();
        
        if (empty($results)) {
            return 0.0;
        }

        $totalDays = 0;
        $count = 0;
        
        foreach ($results as $result) {
            $createdAt = $result['createdAt'];
            $completedAt = $result['completedAt'];
            
            if ($createdAt && $completedAt) {
                $diff = $completedAt->getTimestamp() - $createdAt->getTimestamp();
                $totalDays += $diff / 86400; // Convert to days
                $count++;
            }
        }
        
        return $count > 0 ? round($totalDays / $count, 1) : 0.0;
    }

    /**
     * Returnează statistici de productivitate pentru acțiuni
     * 
     * @param array $teamIds
     * @return array Statistici productivitate
     */
    public function getProductivityStatistics(array $teamIds = []): array
    {
        // Acțiuni create în ultimele 30 de zile
        $recentActions = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->leftJoin('a.retrospective', 'r')
            ->where('a.createdAt >= :recentDate')
            ->setParameter('recentDate', new \DateTime('-30 days'));

        if (!empty($teamIds)) {
            $recentActions->andWhere('r.team IN (:teamIds)')
                         ->setParameter('teamIds', $teamIds);
        }

        $recentCount = (int) $recentActions->getQuery()->getSingleScalarResult();

        // Acțiuni completate în ultimele 30 de zile
        $completedRecent = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->leftJoin('a.retrospective', 'r')
            ->where('a.status = :completed')
            ->andWhere('a.completedAt >= :recentDate')
            ->setParameter('completed', 'completed')
            ->setParameter('recentDate', new \DateTime('-30 days'));

        if (!empty($teamIds)) {
            $completedRecent->andWhere('r.team IN (:teamIds)')
                           ->setParameter('teamIds', $teamIds);
        }

        $completedCount = (int) $completedRecent->getQuery()->getSingleScalarResult();

        // Velocity (acțiuni pe săptămână)
        $velocity = round($completedCount / 4.33, 1); // 30 zile / 7 zile = 4.33 săptămâni

        return [
            'actions_created_last_30_days' => $recentCount,
            'actions_completed_last_30_days' => $completedCount,
            'velocity_actions_per_week' => $velocity,
            'productivity_trend' => $this->calculateProductivityTrend($teamIds)
        ];
    }

    /**
     * Calculează trend-ul de productivitate (pozitiv/negativ)
     * 
     * @param array $teamIds
     * @return string 'up', 'down', sau 'stable'
     */
    private function calculateProductivityTrend(array $teamIds = []): string
    {
        // Acțiuni completate în ultimele 15 zile vs. 15-30 zile în urmă
        $recentCompleted = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->leftJoin('a.retrospective', 'r')
            ->where('a.status = :completed')
            ->andWhere('a.completedAt >= :recentDate')
            ->setParameter('completed', 'completed')
            ->setParameter('recentDate', new \DateTime('-15 days'));

        if (!empty($teamIds)) {
            $recentCompleted->andWhere('r.team IN (:teamIds)')
                           ->setParameter('teamIds', $teamIds);
        }

        $recentCount = (int) $recentCompleted->getQuery()->getSingleScalarResult();

        $previousCompleted = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->leftJoin('a.retrospective', 'r')
            ->where('a.status = :completed')
            ->andWhere('a.completedAt >= :startDate')
            ->andWhere('a.completedAt < :endDate')
            ->setParameter('completed', 'completed')
            ->setParameter('startDate', new \DateTime('-30 days'))
            ->setParameter('endDate', new \DateTime('-15 days'));

        if (!empty($teamIds)) {
            $previousCompleted->andWhere('r.team IN (:teamIds)')
                             ->setParameter('teamIds', $teamIds);
        }

        $previousCount = (int) $previousCompleted->getQuery()->getSingleScalarResult();

        if ($recentCount > $previousCount) {
            return 'up';
        } elseif ($recentCount < $previousCount) {
            return 'down';
        } else {
            return 'stable';
        }
    }

    /**
     * Returnează top utilizatori după acțiuni completate
     * 
     * @param array $teamIds
     * @param int $limit
     * @return array
     */
    public function getTopPerformers(array $teamIds = [], int $limit = 5): array
    {
        $qb = $this->createQueryBuilder('a')
            ->select('u.id, u.firstName, u.lastName, COUNT(a.id) as completedActions')
            ->leftJoin('a.assignedTo', 'u')
            ->leftJoin('a.retrospective', 'r')
            ->where('a.status = :completed')
            ->andWhere('u.id IS NOT NULL')
            ->setParameter('completed', 'completed')
            ->groupBy('u.id')
            ->orderBy('completedActions', 'DESC')
            ->setMaxResults($limit);

        if (!empty($teamIds)) {
            $qb->andWhere('r.team IN (:teamIds)')
               ->setParameter('teamIds', $teamIds);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Returnează datele pentru activitatea lunară pe ultimele 6 luni
     * 
     * @param array $teamIds
     * @return array
     */
    public function getMonthlyActivityData(array $teamIds = []): array
    {
        $months = [];
        $createdData = [];
        $completedData = [];
        
        // Generează ultimele 6 luni
        for ($i = 5; $i >= 0; $i--) {
            $date = new \DateTimeImmutable("-$i months");
            $months[] = $date->format('M');
            
            $startOfMonth = $date->format('Y-m-01');
            $endOfMonth = $date->format('Y-m-t');
            
            // Acțiuni create în această lună
            $createdQb = $this->createQueryBuilder('a')
                ->select('COUNT(a.id)')
                ->leftJoin('a.retrospective', 'r')
                ->where('a.createdAt >= :startDate')
                ->andWhere('a.createdAt <= :endDate')
                ->setParameter('startDate', $startOfMonth)
                ->setParameter('endDate', $endOfMonth . ' 23:59:59');
            
            if (!empty($teamIds)) {
                $createdQb->andWhere('r.team IN (:teamIds)')
                          ->setParameter('teamIds', $teamIds);
            }
            
            $createdCount = (int) $createdQb->getQuery()->getSingleScalarResult();
            $createdData[] = $createdCount;
            
            // Acțiuni completate în această lună
            $completedQb = $this->createQueryBuilder('a')
                ->select('COUNT(a.id)')
                ->leftJoin('a.retrospective', 'r')
                ->where('a.status = :completed')
                ->andWhere('a.completedAt >= :startDate')
                ->andWhere('a.completedAt <= :endDate')
                ->setParameter('completed', 'completed')
                ->setParameter('startDate', $startOfMonth)
                ->setParameter('endDate', $endOfMonth . ' 23:59:59');
            
            if (!empty($teamIds)) {
                $completedQb->andWhere('r.team IN (:teamIds)')
                            ->setParameter('teamIds', $teamIds);
            }
            
            $completedCount = (int) $completedQb->getQuery()->getSingleScalarResult();
            $completedData[] = $completedCount;
        }
        
        return [
            'months' => $months,
            'created' => $createdData,
            'completed' => $completedData
        ];
    }

    /**
     * Formatează timpul de completare în format lizibil
     * 
     * @param float $days
     * @return string
     */
    private function formatCompletionTime(float $days): string
    {
        if ($days < 1) {
            return 'Less than 1 day';
        } elseif ($days < 7) {
            return round($days) . ' days';
        } else {
            $weeks = floor($days / 7);
            $remainingDays = $days % 7;
            
            if ($remainingDays == 0) {
                return $weeks . ' week' . ($weeks > 1 ? 's' : '');
            } else {
                return $weeks . ' week' . ($weeks > 1 ? 's' : '') . ' ' . round($remainingDays) . ' days';
            }
        }
    }
}
