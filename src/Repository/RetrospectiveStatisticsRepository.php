<?php

namespace App\Repository;

use App\Entity\Retrospective;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pentru statistici retrospective
 * 
 * Oferă metode specializate pentru calcularea statisticilor
 * retrospective pentru dashboard și rapoarte
 */
class RetrospectiveStatisticsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Retrospective::class);
    }

    /**
     * Returnează statistici de bază pentru retrospective
     * 
     * @param array $teamIds ID-urile echipelor pentru care se calculează statisticile
     * @return array Statistici detaliate
     */
    public function getBasicStatistics(array $teamIds = []): array
    {
        $qb = $this->createQueryBuilder('r');
        
        if (!empty($teamIds)) {
            $qb->where('r.team IN (:teamIds)')
               ->setParameter('teamIds', $teamIds);
        }

        $total = $qb->select('COUNT(r.id)')
                   ->getQuery()
                   ->getSingleScalarResult();

        // Statistici pe status
        $statusStats = $this->createQueryBuilder('r')
            ->select('r.status, COUNT(r.id) as count')
            ->groupBy('r.status');
            
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

        // Durata medie (doar pentru retrospective completate)
        $avgDuration = $this->calculateAverageDuration($teamIds);

        return [
            'total_retrospectives' => (int) $total,
            'completed_retrospectives' => $statusCounts['completed'] ?? 0,
            'active_retrospectives' => $statusCounts['active'] ?? 0,
            'planned_retrospectives' => $statusCounts['planned'] ?? 0,
            'completion_rate' => $completionRate,
            'average_duration_minutes' => $avgDuration,
            'average_duration_formatted' => $this->formatDuration($avgDuration)
        ];
    }

    /**
     * Calculează durata medie a retrospectivelor completate
     * 
     * @param array $teamIds
     * @return float Durata în minute
     */
    private function calculateAverageDuration(array $teamIds = []): float
    {
        $qb = $this->createQueryBuilder('r')
            ->select('r.startedAt, r.completedAt')
            ->where('r.status = :completed')
            ->andWhere('r.startedAt IS NOT NULL')
            ->andWhere('r.completedAt IS NOT NULL')
            ->setParameter('completed', 'completed');

        if (!empty($teamIds)) {
            $qb->andWhere('r.team IN (:teamIds)')
               ->setParameter('teamIds', $teamIds);
        }

        $results = $qb->getQuery()->getResult();
        
        if (empty($results)) {
            return 0.0;
        }

        $totalMinutes = 0;
        $count = 0;
        
        foreach ($results as $result) {
            $startedAt = $result['startedAt'];
            $completedAt = $result['completedAt'];
            
            if ($startedAt && $completedAt) {
                $diff = $completedAt->getTimestamp() - $startedAt->getTimestamp();
                $totalMinutes += $diff / 60; // Convert to minutes
                $count++;
            }
        }
        
        return $count > 0 ? round($totalMinutes / $count, 1) : 0.0;
    }

    /**
     * Calculează statistici de participare
     * 
     * @param array $teamIds
     * @return array Statistici participare
     */
    public function getParticipationStatistics(array $teamIds = []): array
    {
        // Numărul mediu de item-uri per retrospectivă
        $avgItemsPerRetro = $this->createQueryBuilder('r')
            ->select('AVG(itemCount) as avgItems')
            ->leftJoin('r.items', 'items')
            ->addSelect('COUNT(items.id) as itemCount')
            ->groupBy('r.id')
            ->having('COUNT(items.id) > 0');

        if (!empty($teamIds)) {
            $avgItemsPerRetro->where('r.team IN (:teamIds)')
                            ->setParameter('teamIds', $teamIds);
        }

        // Pentru simplitate, calculăm direct
        $qb = $this->createQueryBuilder('r')
            ->select('COUNT(DISTINCT r.id) as retroCount, COUNT(items.id) as totalItems')
            ->leftJoin('r.items', 'items');

        if (!empty($teamIds)) {
            $qb->where('r.team IN (:teamIds)')
               ->setParameter('teamIds', $teamIds);
        }

        $result = $qb->getQuery()->getSingleResult();
        
        $avgItems = $result['retroCount'] > 0 ? 
            round($result['totalItems'] / $result['retroCount'], 1) : 0;

        return [
            'average_items_per_retrospective' => $avgItems,
            'total_items_created' => (int) $result['totalItems'],
            'total_retrospectives_with_items' => (int) $result['retroCount']
        ];
    }

    /**
     * Returnează retrospectivile recente cu statistici
     * 
     * @param array $teamIds
     * @param int $limit
     * @return array
     */
    public function getRecentRetrospectivesWithStats(array $teamIds = [], int $limit = 5): array
    {
        $qb = $this->createQueryBuilder('r')
            ->select('r', 'COUNT(DISTINCT items.id) as itemCount', 'COUNT(DISTINCT actions.id) as actionCount')
            ->leftJoin('r.items', 'items')
            ->leftJoin('r.actions', 'actions')
            ->groupBy('r.id')
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults($limit);

        if (!empty($teamIds)) {
            $qb->where('r.team IN (:teamIds)')
               ->setParameter('teamIds', $teamIds);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Returnează datele pentru trend-ul de completare retrospective pe ultimele 6 luni
     * 
     * @param array $teamIds
     * @return array
     */
    public function getCompletionTrendData(array $teamIds = []): array
    {
        $months = [];
        $completedData = [];
        $plannedData = [];
        
        // Generează ultimele 6 luni
        for ($i = 5; $i >= 0; $i--) {
            $date = new \DateTimeImmutable("-$i months");
            $months[] = $date->format('M');
            
            $startOfMonth = $date->format('Y-m-01');
            $endOfMonth = $date->format('Y-m-t');
            
            // Retrospective completate în această lună
            $completedQb = $this->createQueryBuilder('r')
                ->select('COUNT(r.id)')
                ->where('r.status = :completed')
                ->andWhere('r.completedAt >= :startDate')
                ->andWhere('r.completedAt <= :endDate')
                ->setParameter('completed', 'completed')
                ->setParameter('startDate', $startOfMonth)
                ->setParameter('endDate', $endOfMonth . ' 23:59:59');
            
            if (!empty($teamIds)) {
                $completedQb->andWhere('r.team IN (:teamIds)')
                           ->setParameter('teamIds', $teamIds);
            }
            
            $completedCount = (int) $completedQb->getQuery()->getSingleScalarResult();
            $completedData[] = $completedCount;
            
            // Retrospective planificate în această lună
            $plannedQb = $this->createQueryBuilder('r')
                ->select('COUNT(r.id)')
                ->where('r.status = :planned')
                ->andWhere('r.createdAt >= :startDate')
                ->andWhere('r.createdAt <= :endDate')
                ->setParameter('planned', 'planned')
                ->setParameter('startDate', $startOfMonth)
                ->setParameter('endDate', $endOfMonth . ' 23:59:59');
            
            if (!empty($teamIds)) {
                $plannedQb->andWhere('r.team IN (:teamIds)')
                          ->setParameter('teamIds', $teamIds);
            }
            
            $plannedCount = (int) $plannedQb->getQuery()->getSingleScalarResult();
            $plannedData[] = $plannedCount;
        }
        
        return [
            'months' => $months,
            'completed' => $completedData,
            'planned' => $plannedData
        ];
    }

    /**
     * Formatează durata din minute în format lizibil
     * 
     * @param float $minutes
     * @return string
     */
    private function formatDuration(float $minutes): string
    {
        if ($minutes < 60) {
            return round($minutes) . ' min';
        }
        
        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;
        
        if ($remainingMinutes == 0) {
            return $hours . 'h';
        }
        
        return $hours . 'h ' . round($remainingMinutes) . 'm';
    }
}
