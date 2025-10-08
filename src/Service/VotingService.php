<?php

namespace App\Service;

use App\Entity\Retrospective;
use App\Entity\RetrospectiveItem;
use App\Entity\RetrospectiveGroup;
use App\Entity\User;
use App\Entity\Vote;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * VotingService
 * 
 * Service for managing voting in retrospectives
 * Handles vote submission, calculation, and aggregation
 */
class VotingService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private HubInterface $hub
    ) {}

    /**
     * Submit vote for item or group
     */
    public function submitVote(
        Retrospective $retrospective,
        User $user,
        string $targetType,
        int $targetId,
        int $voteCount
    ): int {
        // Validate vote count (0-2 per item/group)
        if ($voteCount < 0 || $voteCount > 2) {
            throw new \InvalidArgumentException('Vote count must be between 0 and 2');
        }

        if ($targetType === 'item') {
            $this->voteOnItem($retrospective, $user, $targetId, $voteCount);
        } elseif ($targetType === 'group') {
            $this->voteOnGroup($retrospective, $user, $targetId, $voteCount);
        } else {
            throw new \InvalidArgumentException('Invalid target type');
        }

        // Broadcast vote update
        $this->broadcastVoteUpdate($retrospective->getId(), $targetType, $targetId, $user->getId(), $voteCount);

        // Return remaining votes
        return $this->calculateRemainingVotes($retrospective, $user);
    }

    /**
     * Vote on item
     */
    private function voteOnItem(Retrospective $retrospective, User $user, int $itemId, int $voteCount): void
    {
        $item = $this->entityManager->getRepository(RetrospectiveItem::class)->find($itemId);
        if (!$item || $item->getRetrospective() !== $retrospective) {
            throw new \InvalidArgumentException('Item not found');
        }
        
        $vote = $this->entityManager->getRepository(Vote::class)->findOneBy([
            'user' => $user,
            'retrospectiveItem' => $item
        ]);
        
        if (!$vote) {
            $vote = new Vote();
            $vote->setUser($user);
            $vote->setRetrospectiveItem($item);
            $this->entityManager->persist($vote);
        }
        
        // Update vote count (0 means removing the vote)
        if ($voteCount === 0 && $vote->getId()) {
            $this->entityManager->remove($vote);
        } else {
            $vote->setVoteCount($voteCount);
        }
        
        $this->entityManager->flush();
    }

    /**
     * Vote on group
     */
    private function voteOnGroup(Retrospective $retrospective, User $user, int $groupId, int $voteCount): void
    {
        $group = $this->entityManager->getRepository(RetrospectiveGroup::class)->find($groupId);
        if (!$group || $group->getRetrospective() !== $retrospective) {
            throw new \InvalidArgumentException('Group not found');
        }
        
        $vote = $this->entityManager->getRepository(Vote::class)->findOneBy([
            'user' => $user,
            'retrospectiveGroup' => $group
        ]);
        
        if (!$vote) {
            $vote = new Vote();
            $vote->setUser($user);
            $vote->setRetrospectiveGroup($group);
            $this->entityManager->persist($vote);
        }
        
        // Update vote count (0 means removing the vote)
        if ($voteCount === 0 && $vote->getId()) {
            $this->entityManager->remove($vote);
        } else {
            $vote->setVoteCount($voteCount);
        }
        
        $this->entityManager->flush();
    }

    /**
     * Calculate remaining votes for user
     */
    public function calculateRemainingVotes(Retrospective $retrospective, User $user): int
    {
        $allowedVotes = $retrospective->getVoteNumbers() ?? 10;
        
        // Get all votes by this user for this retrospective
        $userVotes = $this->entityManager->getRepository(Vote::class)
            ->createQueryBuilder('v')
            ->leftJoin('v.retrospectiveItem', 'ri')
            ->leftJoin('v.retrospectiveGroup', 'rg')
            ->where('v.user = :user')
            ->andWhere('(ri.retrospective = :retrospective OR rg.retrospective = :retrospective)')
            ->setParameter('user', $user)
            ->setParameter('retrospective', $retrospective)
            ->getQuery()
            ->getResult();
        
        // Calculate total votes used
        $totalVotesUsed = 0;
        foreach ($userVotes as $vote) {
            $totalVotesUsed += $vote->getVoteCount();
        }
        
        return max(0, $allowedVotes - $totalVotesUsed);
    }

    /**
     * Get votes for user in retrospective
     */
    public function getVotesForUser(Retrospective $retrospective, User $user): array
    {
        $votes = $this->entityManager->getRepository(Vote::class)
            ->findByUserAndRetrospective($user, $retrospective);
        
        $votesData = [];
        foreach ($votes as $vote) {
            $voteData = ['voteCount' => $vote->getVoteCount()];
            
            if ($vote->getRetrospectiveItem()) {
                $voteData['targetType'] = 'item';
                $voteData['targetId'] = $vote->getRetrospectiveItem()->getId();
            } elseif ($vote->getRetrospectiveGroup()) {
                $voteData['targetType'] = 'group';
                $voteData['targetId'] = $vote->getRetrospectiveGroup()->getId();
            }
            
            $votesData[] = $voteData;
        }

        return $votesData;
    }

    /**
     * Get items with aggregated votes
     */
    public function getItemsWithAggregatedVotes(array $items, array $groups): array
    {
        $combined = [];
        
        // Process individual items (not in groups)
        foreach ($items as $item) {
            if (!$item->getGroup()) {
                $totalVotes = $this->getItemTotalVotes($item);
                
                $combined[] = [
                    'type' => 'item',
                    'entity' => $item,
                    'totalVotes' => $totalVotes,
                    'category' => $this->getItemCategory($item)
                ];
            }
        }
        
        // Process groups
        foreach ($groups as $group) {
            $totalVotes = $this->getGroupTotalVotes($group);
            
            $combined[] = [
                'type' => 'group',
                'entity' => $group,
                'totalVotes' => $totalVotes,
                'category' => $this->getGroupCategory($group)
            ];
        }
        
        // Sort: non-discussed first (by votes desc), then discussed (by votes desc)
        usort($combined, function($a, $b) {
            $aEntity = $a['entity'];
            $bEntity = $b['entity'];
            
            $aDiscussed = ($aEntity instanceof RetrospectiveItem) ? $aEntity->isDiscussed() : 
                         (method_exists($aEntity, 'isDiscussed') ? $aEntity->isDiscussed() : false);
            $bDiscussed = ($bEntity instanceof RetrospectiveItem) ? $bEntity->isDiscussed() : 
                         (method_exists($bEntity, 'isDiscussed') ? $bEntity->isDiscussed() : false);
            
            if ($aDiscussed === $bDiscussed) {
                return $b['totalVotes'] <=> $a['totalVotes'];
            }
            
            return $aDiscussed ? 1 : -1;
        });
        
        return $combined;
    }

    /**
     * Get total votes for item
     */
    private function getItemTotalVotes(RetrospectiveItem $item): int
    {
        $result = $this->entityManager
            ->getRepository(Vote::class)
            ->createQueryBuilder('v')
            ->select('SUM(v.voteCount)')
            ->where('v.retrospectiveItem = :item')
            ->setParameter('item', $item)
            ->getQuery()
            ->getSingleScalarResult();
        
        return (int)($result ?? 0);
    }

    /**
     * Get total votes for group
     */
    private function getGroupTotalVotes(RetrospectiveGroup $group): int
    {
        $totalVotes = 0;
        
        // Sum votes for all items in the group
        foreach ($group->getItems() as $groupItem) {
            $totalVotes += $this->getItemTotalVotes($groupItem);
        }
        
        // Add votes directly on the group
        $result = $this->entityManager
            ->getRepository(Vote::class)
            ->createQueryBuilder('v')
            ->select('SUM(v.voteCount)')
            ->where('v.retrospectiveGroup = :group')
            ->setParameter('group', $group)
            ->getQuery()
            ->getSingleScalarResult();
        
        $totalVotes += (int)($result ?? 0);
        
        return $totalVotes;
    }

    /**
     * Broadcast vote update
     */
    private function broadcastVoteUpdate(
        int $retrospectiveId,
        string $targetType,
        int $targetId,
        int $userId,
        int $voteCount
    ): void {
        $update = new Update(
            "retrospective/{$retrospectiveId}",
            json_encode([
                'type' => 'vote_updated',
                'targetType' => $targetType,
                'targetId' => $targetId,
                'userId' => $userId,
                'voteCount' => $voteCount
            ])
        );
        $this->hub->publish($update);
    }

    /**
     * Helper methods
     */
    private function getItemCategory(RetrospectiveItem $item): string
    {
        if ($item->isWrong()) return 'wrong';
        if ($item->isGood()) return 'good';
        if ($item->isImproved()) return 'improved';
        if ($item->isRandom()) return 'random';
        return 'unknown';
    }

    private function getGroupCategory(RetrospectiveGroup $group): string
    {
        $positionX = $group->getPositionX();
        return match($positionX) {
            0 => 'wrong',
            1 => 'good',
            2 => 'improved',
            3 => 'random',
            default => 'unknown'
        };
    }
}

