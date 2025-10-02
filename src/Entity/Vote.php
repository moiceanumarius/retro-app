<?php

namespace App\Entity;

use App\Repository\VoteRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: VoteRepository::class)]
#[ORM\Table(name: 'votes')]
class Vote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: RetrospectiveItem::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?RetrospectiveItem $retrospectiveItem = null;

    #[ORM\ManyToOne(targetEntity: RetrospectiveGroup::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?RetrospectiveGroup $retrospectiveGroup = null;

    #[ORM\Column]
    private int $voteCount = 0;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getRetrospectiveItem(): ?RetrospectiveItem
    {
        return $this->retrospectiveItem;
    }

    public function setRetrospectiveItem(?RetrospectiveItem $retrospectiveItem): static
    {
        $this->retrospectiveItem = $retrospectiveItem;
        return $this;
    }

    public function getRetrospectiveGroup(): ?RetrospectiveGroup
    {
        return $this->retrospectiveGroup;
    }

    public function setRetrospectiveGroup(?RetrospectiveGroup $retrospectiveGroup): static
    {
        $this->retrospectiveGroup = $retrospectiveGroup;
        return $this;
    }

    public function getVoteCount(): int
    {
        return $this->voteCount;
    }

    public function setVoteCount(int $voteCount): static
    {
        $this->voteCount = $voteCount;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }
}

