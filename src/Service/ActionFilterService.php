<?php

namespace App\Service;

use App\Entity\Team;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;

/**
 * ActionFilterService
 * 
 * Service for filtering and paginating actions
 * Handles query building, sorting, and statistics calculation
 */
class ActionFilterService
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    /**
     * Get paginated and filtered actions for a team
     */
    public function getPaginatedActionsForTeam(
        Team $team,
        string $filterStatus = 'all',
        string $filterReview = '',
        int $page = 1,
        int $limit = 10,
        string $sortField = 'created_at',
        string $sortDirection = 'desc'
    ): array {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('a')
           ->from(\App\Entity\RetrospectiveAction::class, 'a')
           ->leftJoin('a.retrospective', 'r')
           ->where('r.team = :team')
           ->setParameter('team', $team);
        
        // Apply sorting
        $this->applySorting($qb, $sortField, $sortDirection);
        
        // Apply status filter
        $this->applyStatusFilter($qb, $filterStatus);
        
        // Apply review filter
        $this->applyReviewFilter($qb, $filterReview);
        
        // Apply pagination
        $offset = ($page - 1) * $limit;
        $qb->setFirstResult($offset)
           ->setMaxResults($limit);
        
        $paginator = new Paginator($qb);
        $totalItems = count($paginator);
        $totalPages = ceil($totalItems / $limit);
        
        return [
            'actions' => $paginator,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $totalPages,
                'total_items' => $totalItems,
                'items_per_page' => $limit,
                'has_previous' => $page > 1,
                'has_next' => $page < $totalPages,
                'sort' => $sortField,
                'direction' => $sortDirection,
            ]
        ];
    }

    /**
     * Apply sorting to query builder
     */
    private function applySorting($qb, string $sortField, string $sortDirection): void
    {
        $allowedSortFields = ['created_at', 'due_date', 'status'];
        $allowedDirections = ['asc', 'desc'];
        
        if (!in_array($sortField, $allowedSortFields) || !in_array($sortDirection, $allowedDirections)) {
            $sortField = 'created_at';
            $sortDirection = 'desc';
        }
        
        $fieldMapping = [
            'created_at' => 'a.createdAt',
            'due_date' => 'a.dueDate',
            'status' => 'a.status'
        ];
        
        // For due_date, handle null values properly
        if ($sortField === 'due_date') {
            $qb->orderBy('CASE WHEN a.dueDate IS NULL THEN 1 ELSE 0 END', 'ASC') // NULLs last
               ->addOrderBy('a.dueDate', strtoupper($sortDirection));
        } else {
            $qb->orderBy($fieldMapping[$sortField], strtoupper($sortDirection));
        }
    }

    /**
     * Apply status filter to query builder
     */
    private function applyStatusFilter($qb, string $filterStatus): void
    {
        if ($filterStatus === 'all') {
            return;
        }
        
        // Check if multiple statuses are provided (comma-separated)
        if (strpos($filterStatus, ',') !== false) {
            $statuses = explode(',', $filterStatus);
            $qb->andWhere('a.status IN (:statuses)')
               ->setParameter('statuses', $statuses);
        } else {
            $qb->andWhere('a.status = :status')
               ->setParameter('status', $filterStatus);
        }
    }

    /**
     * Apply review filter to query builder
     */
    private function applyReviewFilter($qb, string $filterReview): void
    {
        if ($filterReview === 'reviewed') {
            $qb->andWhere('a.isReviewed = :reviewed')
               ->setParameter('reviewed', true);
        } elseif ($filterReview === 'not-reviewed') {
            $qb->andWhere('a.isReviewed = :notReviewed')
               ->setParameter('notReviewed', false);
        }
    }

    /**
     * Get status statistics for a team
     */
    public function getStatusStatisticsForTeam(Team $team): array
    {
        $qbStats = $this->entityManager->createQueryBuilder();
        $qbStats->select('a.status, COUNT(a) as count')
                ->from(\App\Entity\RetrospectiveAction::class, 'a')
                ->leftJoin('a.retrospective', 'r')
                ->where('r.team = :team')
                ->setParameter('team', $team)
                ->groupBy('a.status');
        
        $statusStats = [];
        foreach ($qbStats->getQuery()->getResult() as $stat) {
            $statusStats[$stat['status']] = $stat['count'];
        }
        
        // Calculate overdue actions
        $overdueCount = $this->getOverdueCountForTeam($team);
        $statusStats['overdue'] = $overdueCount;
        
        return $statusStats;
    }

    /**
     * Get overdue actions count for a team
     */
    public function getOverdueCountForTeam(Team $team): int
    {
        $qbOverdue = $this->entityManager->createQueryBuilder();
        $qbOverdue->select('COUNT(a) as count')
                  ->from(\App\Entity\RetrospectiveAction::class, 'a')
                  ->leftJoin('a.retrospective', 'r')
                  ->where('r.team = :team')
                  ->andWhere('a.dueDate < :currentDate')
                  ->andWhere('a.status NOT IN (:completedStatuses)')
                  ->setParameter('team', $team)
                  ->setParameter('currentDate', new \DateTime())
                  ->setParameter('completedStatuses', ['completed', 'cancelled']);
        
        return (int) $qbOverdue->getQuery()->getSingleScalarResult();
    }

    /**
     * Get all actions for a team without pagination
     */
    public function getActionsForTeam(Team $team): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('a')
           ->from(\App\Entity\RetrospectiveAction::class, 'a')
           ->leftJoin('a.retrospective', 'r')
           ->where('r.team = :team')
           ->setParameter('team', $team)
           ->orderBy('a.createdAt', 'DESC');
        
        return $qb->getQuery()->getResult();
    }

    /**
     * Get actions for export
     */
    public function getActionsForExport(
        Team $team,
        string $filterStatus = 'all',
        string $filterReview = ''
    ): array {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('a')
           ->from(\App\Entity\RetrospectiveAction::class, 'a')
           ->leftJoin('a.retrospective', 'r')
           ->where('r.team = :team')
           ->setParameter('team', $team);
        
        // Apply filters
        $this->applyStatusFilter($qb, $filterStatus);
        $this->applyReviewFilter($qb, $filterReview);
        
        $qb->orderBy('a.createdAt', 'DESC');
        
        return $qb->getQuery()->getResult();
    }
}

