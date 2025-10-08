<?php

namespace App\Service;

use App\Repository\RetrospectiveStatisticsRepository;
use App\Repository\ActionStatisticsRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * StatisticsService
 * 
 * Service pentru gestionarea logicii de statistici și analytics
 * Extrage logica complexă din StatisticsController pentru testabilitate
 */
class StatisticsService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RetrospectiveStatisticsRepository $retrospectiveStatsRepository,
        private ActionStatisticsRepository $actionStatsRepository
    ) {}

    /**
     * Get completion trend data for teams
     */
    public function getCompletionTrendData(array $teamIds): array
    {
        return $this->retrospectiveStatsRepository->getCompletionTrendData($teamIds);
    }

    /**
     * Get monthly activity data for teams
     */
    public function getMonthlyActivityData(array $teamIds): array
    {
        return $this->actionStatsRepository->getMonthlyActivityData($teamIds);
    }

    /**
     * Get basic retrospective statistics for teams
     */
    public function getBasicRetrospectiveStatistics(array $teamIds): array
    {
        return $this->retrospectiveStatsRepository->getBasicStatistics($teamIds);
    }

    /**
     * Get action statistics for teams
     */
    public function getActionStatistics(array $teamIds): array
    {
        return $this->actionStatsRepository->getActionStatistics($teamIds);
    }

    /**
     * Get participation statistics for teams
     */
    public function getParticipationStatistics(array $teamIds): array
    {
        return $this->retrospectiveStatsRepository->getParticipationStatistics($teamIds);
    }

    /**
     * Get productivity statistics for teams
     */
    public function getProductivityStatistics(array $teamIds): array
    {
        return $this->actionStatsRepository->getProductivityStatistics($teamIds);
    }

    /**
     * Get team statistics for teams
     */
    public function getTeamStatistics(array $teamIds): array
    {
        return $this->retrospectiveStatsRepository->getTeamStatistics($teamIds);
    }

    /**
     * Get category distribution data for teams
     */
    public function getCategoryDistributionData(array $teamIds): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('ri.category, COUNT(ri) as count')
           ->from(\App\Entity\RetrospectiveItem::class, 'ri')
           ->join('ri.retrospective', 'r')
           ->where('r.team IN (:teams)')
           ->setParameter('teams', $teamIds)
           ->groupBy('ri.category');

        $results = $qb->getQuery()->getResult();
        
        $categoryLabels = [
            'wrong' => 'What went wrong',
            'good' => 'What went good', 
            'improved' => 'What can be improved',
            'random' => 'Random feedback'
        ];

        $categoryData = [];
        foreach ($results as $result) {
            $category = $result['category'];
            $label = $categoryLabels[$category] ?? ucfirst($category);
            $categoryData[$label] = $result['count'];
        }

        return $categoryData;
    }

    /**
     * Get category trend data over time for teams
     */
    public function getCategoryTrendData(array $teamIds, \DateTime $startDate): array
    {
        // Convert team IDs to comma-separated string for IN clause
        $teamIdsString = implode(',', array_map('intval', $teamIds));
        
        $sql = "
            SELECT ri.category, 
                   DATE_FORMAT(r.created_at, '%Y-%m') as month, 
                   COUNT(ri.id) as count
            FROM retrospective_item ri 
            JOIN retrospective r ON ri.retrospective_id = r.id 
            WHERE r.team_id IN ($teamIdsString) 
            AND r.created_at >= :startDate
            GROUP BY ri.category, month 
            ORDER BY month ASC
        ";
        
        $stmt = $this->entityManager->getConnection()->prepare($sql);
        $stmt->bindValue('startDate', $startDate->format('Y-m-d H:i:s'));
        $result = $stmt->executeQuery();
        $results = $result->fetchAllAssociative();

        // Process results from raw SQL
        $categoryLabels = [
            'wrong' => 'What went wrong',
            'good' => 'What went good', 
            'improved' => 'What can be improved',
            'random' => 'Random feedback'
        ];
        
        $trendData = [];
        $months = [];
        
        // Initialize all categories
        foreach ($categoryLabels as $key => $label) {
            $trendData[$label] = [];
        }
        
        // Process results
        foreach ($results as $result) {
            $category = $result['category'];
            $month = $result['month'];
            $count = $result['count'];
            
            $label = $categoryLabels[$category] ?? ucfirst($category);
            if (!isset($trendData[$label])) {
                $trendData[$label] = [];
            }
            $trendData[$label][$month] = $count;
            
            if (!in_array($month, $months)) {
                $months[] = $month;
            }
        }
        
        // Fill missing months with 0
        sort($months);
        foreach ($trendData as $category => $data) {
            foreach ($months as $month) {
                if (!isset($data[$month])) {
                    $trendData[$category][$month] = 0;
                }
            }
            // Sort by month
            ksort($trendData[$category]);
        }

        return [
            'months' => $months,
            'categories' => $trendData
        ];
    }
}
