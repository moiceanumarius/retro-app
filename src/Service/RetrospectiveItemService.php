<?php

namespace App\Service;

use App\Entity\Retrospective;
use App\Entity\RetrospectiveItem;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * RetrospectiveItemService
 * 
 * Service for managing retrospective items
 * Handles item CRUD operations and real-time updates
 */
class RetrospectiveItemService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private HubInterface $hub
    ) {}

    /**
     * Get items for retrospective
     */
    public function getItemsForRetrospective(Retrospective $retrospective, ?User $currentUser = null): array
    {
        // In feedback step, users only see their own posts
        if ($retrospective->isInStep('feedback') && $currentUser) {
            return $this->entityManager->getRepository(RetrospectiveItem::class)
                ->findBy([
                    'retrospective' => $retrospective,
                    'author' => $currentUser
                ], ['createdAt' => 'ASC']);
        }

        // In other steps, users see all posts
        return $this->entityManager->getRepository(RetrospectiveItem::class)
            ->findBy(['retrospective' => $retrospective], ['createdAt' => 'ASC']);
    }

    /**
     * Create item
     */
    public function createItem(
        Retrospective $retrospective,
        User $author,
        string $content,
        string $category
    ): RetrospectiveItem {
        $item = new RetrospectiveItem();
        $item->setRetrospective($retrospective);
        $item->setAuthor($author);
        $item->setContent($content);
        $item->setCategory($category);
        
        // Set position to the end of the column
        $existingItems = $this->entityManager->getRepository(RetrospectiveItem::class)
            ->findBy(['retrospective' => $retrospective, 'category' => $category], ['position' => 'DESC'], 1);
        $item->setPosition($existingItems ? $existingItems[0]->getPosition() + 1 : 0);
        
        $this->entityManager->persist($item);
        $this->entityManager->flush();
        
        // Broadcast new item
        $this->broadcastItemAdded($retrospective->getId(), $item);
        
        return $item;
    }

    /**
     * Update item
     */
    public function updateItem(RetrospectiveItem $item, string $content): void
    {
        $item->setContent($content);
        $item->setUpdatedAt(new \DateTime());
        
        $this->entityManager->flush();
    }

    /**
     * Delete item
     */
    public function deleteItem(RetrospectiveItem $item): void
    {
        $this->entityManager->remove($item);
        $this->entityManager->flush();
    }

    /**
     * Check if user can edit item
     */
    public function canEditItem(RetrospectiveItem $item, User $user): bool
    {
        return $item->getAuthor()->getId() === $user->getId();
    }

    /**
     * Mark item as discussed
     */
    public function markAsDiscussed(RetrospectiveItem $item, User $user): void
    {
        $item->setIsDiscussed(true);
        $this->entityManager->persist($item);
        $this->entityManager->flush();
        
        // Broadcast discussion update
        $update = new Update(
            "retrospectives/{$item->getRetrospective()->getId()}/discussion",
            json_encode([
                'type' => 'item_discussed',
                'itemId' => $item->getId(),
                'itemType' => 'item',
                'memberName' => $user->getFullName(),
                'timestamp' => time()
            ])
        );
        $this->hub->publish($update);
    }

    /**
     * Broadcast item added
     */
    private function broadcastItemAdded(int $retrospectiveId, RetrospectiveItem $item): void
    {
        $itemData = [
            'type' => 'item_added',
            'item' => [
                'id' => $item->getId(),
                'content' => $item->getContent(),
                'category' => $item->getCategory(),
                'author' => [
                    'firstName' => $item->getAuthor()->getFirstName(),
                    'lastName' => $item->getAuthor()->getLastName()
                ],
                'createdAt' => $item->getCreatedAt()->format('H:i')
            ]
        ];

        // Broadcast to items topic
        $update1 = new Update(
            "retrospective/{$retrospectiveId}/items",
            json_encode($itemData)
        );
        $this->hub->publish($update1);
        
        // Also broadcast to general retrospective topic
        $update2 = new Update(
            "retrospective/{$retrospectiveId}",
            json_encode($itemData)
        );
        $this->hub->publish($update2);
    }

    /**
     * Get item category label
     */
    public function getItemCategory(RetrospectiveItem $item): string
    {
        if ($item->isWrong()) return 'wrong';
        if ($item->isGood()) return 'good';
        if ($item->isImproved()) return 'improved';
        if ($item->isRandom()) return 'random';
        return 'unknown';
    }

    /**
     * Filter items by category
     */
    public function filterItemsByCategory(array $items, string $category): array
    {
        return array_filter($items, function($item) use ($category) {
            $itemCategory = $this->getItemCategory($item);
            return $itemCategory === $category && !$item->getGroup();
        });
    }
}

