<?php

namespace App\Service;

use App\Entity\Retrospective;
use App\Entity\RetrospectiveGroup;
use App\Entity\RetrospectiveItem;
use App\Entity\User;
use App\Entity\Vote;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * RetrospectiveGroupService
 * 
 * Service for managing retrospective groups
 * Handles group creation, item management, and positioning
 */
class RetrospectiveGroupService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private HubInterface $hub
    ) {}

    /**
     * Create group from items
     */
    public function createGroup(
        Retrospective $retrospective,
        array $itemIds,
        string $category,
        ?int $targetPosition = null
    ): RetrospectiveGroup {
        if (count($itemIds) < 2) {
            throw new \InvalidArgumentException('At least 2 items are required to create a group');
        }

        $group = new RetrospectiveGroup();
        $group->setRetrospective($retrospective);
        
        // Set positionX based on category
        $positionX = match($category) {
            'wrong' => 0,
            'good' => 1,
            'improved' => 2,
            'random' => 3,
            default => 0
        };
        $group->setPositionX($positionX);
        $group->setPositionY(0);
        $group->setTitle('Group ' . (count($retrospective->getGroups()) + 1));
        $group->setDisplayCategory($category);
        
        // Set position
        if ($targetPosition !== null) {
            $this->insertGroupAtPosition($group, $retrospective, $category, $targetPosition);
        } else {
            $this->appendGroupToEnd($group, $retrospective, $category);
        }

        $this->entityManager->persist($group);

        // Add items to group and remove individual votes
        foreach ($itemIds as $itemId) {
            $item = $this->entityManager->getRepository(RetrospectiveItem::class)->find($itemId);
            if ($item && $item->getRetrospective() === $retrospective) {
                $group->addItem($item);
                $this->removeItemVotes($item);
            }
        }

        $this->entityManager->flush();

        // Broadcast group creation
        $this->broadcastGroupCreated($retrospective->getId(), $group, $itemIds);

        return $group;
    }

    /**
     * Insert group at specific position
     */
    private function insertGroupAtPosition(
        RetrospectiveGroup $group,
        Retrospective $retrospective,
        string $category,
        int $position
    ): void {
        $group->setPosition($position);
        
        // Shift other groups down
        $this->entityManager->getRepository(RetrospectiveGroup::class)
            ->createQueryBuilder('g')
            ->update()
            ->set('g.position', 'g.position + 1')
            ->where('g.retrospective = :retrospective')
            ->andWhere('g.displayCategory = :category')
            ->andWhere('g.position >= :position')
            ->setParameter('retrospective', $retrospective)
            ->setParameter('category', $category)
            ->setParameter('position', $position)
            ->getQuery()
            ->execute();
    }

    /**
     * Append group to end of column
     */
    private function appendGroupToEnd(
        RetrospectiveGroup $group,
        Retrospective $retrospective,
        string $category
    ): void {
        $existingGroups = $this->entityManager->getRepository(RetrospectiveGroup::class)
            ->findBy(['retrospective' => $retrospective, 'displayCategory' => $category], ['position' => 'DESC'], 1);
        $group->setPosition($existingGroups ? $existingGroups[0]->getPosition() + 1 : 0);
    }

    /**
     * Remove votes on item
     */
    private function removeItemVotes(RetrospectiveItem $item): void
    {
        $itemVotes = $this->entityManager->getRepository(Vote::class)->findBy([
            'retrospectiveItem' => $item
        ]);
        foreach ($itemVotes as $vote) {
            $this->entityManager->remove($vote);
        }
    }

    /**
     * Add item to group
     */
    public function addItemToGroup(RetrospectiveItem $item, RetrospectiveGroup $group): void
    {
        $group->addItem($item);
        $item->setGroup($group);
        
        $this->entityManager->flush();
        
        // Broadcast update
        $update = new Update(
            "retrospective/{$group->getRetrospective()->getId()}/review",
            json_encode([
                'type' => 'item_added_to_group',
                'item_id' => $item->getId(),
                'group_id' => $group->getId()
            ])
        );
        $this->hub->publish($update);
    }

    /**
     * Separate item from group
     */
    public function separateItemFromGroup(RetrospectiveItem $item): void
    {
        $group = $item->getGroup();
        if (!$group) {
            throw new \InvalidArgumentException('Item is not in a group');
        }

        $retrospectiveId = $group->getRetrospective()->getId();
        $groupId = $group->getId();
        
        // Remove item from group
        $group->removeItem($item);
        $item->setGroup(null);

        // If group has 1 or fewer items, delete it
        if ($group->getItems()->count() <= 1) {
            if ($group->getItems()->count() === 1) {
                $remainingItem = $group->getItems()->first();
                $group->removeItem($remainingItem);
                $remainingItem->setGroup(null);
            }
            $this->entityManager->remove($group);
        }

        $this->entityManager->flush();

        // Broadcast update
        $update = new Update(
            "retrospective/{$retrospectiveId}/review",
            json_encode([
                'type' => 'item_separated',
                'item_id' => $item->getId(),
                'group_id' => $groupId
            ])
        );
        $this->hub->publish($update);
    }

    /**
     * Update group position
     */
    public function updateGroupPosition(RetrospectiveGroup $group, int $positionX, int $positionY): void
    {
        $group->setPositionX($positionX);
        $group->setPositionY($positionY);
        $group->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->flush();

        // Broadcast update
        $update = new Update(
            "retrospective/{$group->getRetrospective()->getId()}/review",
            json_encode([
                'type' => 'group_moved',
                'group_id' => $group->getId(),
                'position_x' => $positionX,
                'position_y' => $positionY
            ])
        );
        $this->hub->publish($update);
    }

    /**
     * Mark group as discussed
     */
    public function markGroupAsDiscussed(RetrospectiveGroup $group, User $user): void
    {
        $group->setIsDiscussed(true);
        $this->entityManager->persist($group);
        $this->entityManager->flush();
        
        // Broadcast discussion update
        $update = new Update(
            "retrospectives/{$group->getRetrospective()->getId()}/discussion",
            json_encode([
                'type' => 'item_discussed',
                'itemId' => $group->getId(),
                'itemType' => 'group',
                'memberName' => $user->getFullName(),
                'timestamp' => time()
            ])
        );
        $this->hub->publish($update);
    }

    /**
     * Broadcast group created
     */
    private function broadcastGroupCreated(int $retrospectiveId, RetrospectiveGroup $group, array $itemIds): void
    {
        $groupData = [
            'type' => 'group_created',
            'group' => [
                'id' => $group->getId(),
                'title' => $group->getTitle(),
                'position_x' => $group->getPositionX(),
                'position_y' => $group->getPositionY(),
                'item_count' => $group->getItems()->count(),
            ],
            'item_ids' => $itemIds
        ];

        // Broadcast to review topic
        $update1 = new Update(
            "retrospective/{$retrospectiveId}/review",
            json_encode($groupData)
        );
        $this->hub->publish($update1);
        
        // Also broadcast to general retrospective topic
        $update2 = new Update(
            "retrospective/{$retrospectiveId}",
            json_encode($groupData)
        );
        $this->hub->publish($update2);
    }

    /**
     * Get group category
     */
    public function getGroupCategory(RetrospectiveGroup $group): string
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

    /**
     * Reorder items and groups in a category
     */
    public function reorderElements(
        Retrospective $retrospective,
        string $category,
        array $orderedElements
    ): void {
        foreach ($orderedElements as $index => $element) {
            if ($element['type'] === 'item') {
                $item = $this->entityManager->getRepository(RetrospectiveItem::class)->find($element['id']);
                if ($item && $item->getRetrospective() === $retrospective && $item->getCategory() === $category) {
                    $item->setPosition($index);
                }
            } elseif ($element['type'] === 'group') {
                $group = $this->entityManager->getRepository(RetrospectiveGroup::class)->find($element['id']);
                if ($group && $group->getRetrospective() === $retrospective && $group->getDisplayCategory() === $category) {
                    $group->setPosition($index);
                }
            }
        }

        $this->entityManager->flush();

        // Broadcast reorder update
        $this->broadcastItemsReordered($retrospective->getId(), $category, $orderedElements);
    }

    /**
     * Broadcast items reordered
     */
    private function broadcastItemsReordered(int $retrospectiveId, string $category, array $orderedElements): void
    {
        $itemIds = array_map(fn($e) => $e['type'] === 'item' ? $e['id'] : null, $orderedElements);
        $groupIds = array_map(fn($e) => $e['type'] === 'group' ? $e['id'] : null, $orderedElements);
        
        $updateData = [
            'type' => 'items_reordered',
            'category' => $category,
            'item_ids' => array_filter($itemIds),
            'group_ids' => array_filter($groupIds)
        ];

        // Broadcast to review topic
        $update1 = new Update(
            "retrospective/{$retrospectiveId}/review",
            json_encode($updateData)
        );
        $this->hub->publish($update1);
        
        // Also broadcast to general topic
        $update2 = new Update(
            "retrospective/{$retrospectiveId}",
            json_encode($updateData)
        );
        $this->hub->publish($update2);
    }

    /**
     * Combine and sort items and groups by position
     */
    public function combineAndSortByPosition(array $items, array $groups): array
    {
        $combined = [];
        
        // Add items with their position
        foreach ($items as $item) {
            $combined[] = [
                'type' => 'item',
                'entity' => $item,
                'position' => $item->getPosition()
            ];
        }
        
        // Add groups with their position
        foreach ($groups as $group) {
            $combined[] = [
                'type' => 'group',
                'entity' => $group,
                'position' => $group->getPositionY()
            ];
        }
        
        // Sort by position
        usort($combined, fn($a, $b) => $a['position'] <=> $b['position']);
        
        return $combined;
    }
}

