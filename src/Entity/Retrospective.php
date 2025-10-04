<?php

namespace App\Entity;

use App\Repository\RetrospectiveRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RetrospectiveRepository::class)]
class Retrospective
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(inversedBy: 'retrospectives')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Team $team = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $facilitator = null;

    #[ORM\Column(length: 50)]
    private ?string $status = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $scheduledAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $completedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\Column(length: 50)]
    private ?string $currentStep = null;

    #[ORM\Column(nullable: true)]
    private ?int $timerDuration = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $timerStartedAt = null;

    #[ORM\Column]
    private ?int $voteNumbers = null;

    #[ORM\OneToMany(mappedBy: 'retrospective', targetEntity: RetrospectiveItem::class, cascade: ['persist', 'remove'])]
    private Collection $items;

    #[ORM\OneToMany(mappedBy: 'retrospective', targetEntity: RetrospectiveGroup::class, cascade: ['persist', 'remove'])]
    private Collection $groups;

    #[ORM\OneToMany(mappedBy: 'retrospective', targetEntity: RetrospectiveAction::class, cascade: ['persist', 'remove'])]
    private Collection $actions;

    public function __construct()
    {
        $this->items = new ArrayCollection();
        $this->groups = new ArrayCollection();
        $this->actions = new ArrayCollection();
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        $this->status = 'planned';
        $this->currentStep = 'feedback';
        $this->voteNumbers = 5;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getTeam(): ?Team
    {
        return $this->team;
    }

    public function setTeam(?Team $team): static
    {
        $this->team = $team;
        return $this;
    }

    public function getFacilitator(): ?User
    {
        return $this->facilitator;
    }

    public function setFacilitator(?User $facilitator): static
    {
        $this->facilitator = $facilitator;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getScheduledAt(): ?\DateTimeInterface
    {
        return $this->scheduledAt;
    }

    public function setScheduledAt(\DateTimeInterface $scheduledAt): static
    {
        $this->scheduledAt = $scheduledAt;
        return $this;
    }

    public function getStartedAt(): ?\DateTimeInterface
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeInterface $startedAt): static
    {
        $this->startedAt = $startedAt;
        return $this;
    }

    public function getCompletedAt(): ?\DateTimeInterface
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeInterface $completedAt): static
    {
        $this->completedAt = $completedAt;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * @return Collection<int, RetrospectiveItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(RetrospectiveItem $item): static
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setRetrospective($this);
        }
        return $this;
    }

    public function removeItem(RetrospectiveItem $item): static
    {
        if ($this->items->removeElement($item)) {
            if ($item->getRetrospective() === $this) {
                $item->setRetrospective(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, RetrospectiveAction>
     */
    public function getActions(): Collection
    {
        return $this->actions;
    }

    public function addAction(RetrospectiveAction $action): static
    {
        if (!$this->actions->contains($action)) {
            $this->actions->add($action);
            $action->setRetrospective($this);
        }
        return $this;
    }

    public function removeAction(RetrospectiveAction $action): static
    {
        if ($this->actions->removeElement($action)) {
            if ($action->getRetrospective() === $this) {
                $action->setRetrospective(null);
            }
        }
        return $this;
    }

    public function isPlanned(): bool
    {
        return $this->status === 'planned';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function getCurrentStep(): ?string
    {
        return $this->currentStep;
    }

    public function setCurrentStep(string $currentStep): static
    {
        $this->currentStep = $currentStep;
        return $this;
    }

    public function getTimerDuration(): ?int
    {
        return $this->timerDuration;
    }

    public function setTimerDuration(?int $timerDuration): static
    {
        $this->timerDuration = $timerDuration;
        return $this;
    }

    public function getTimerStartedAt(): ?\DateTimeInterface
    {
        return $this->timerStartedAt;
    }

    public function setTimerStartedAt(?\DateTimeInterface $timerStartedAt): static
    {
        $this->timerStartedAt = $timerStartedAt;
        return $this;
    }

    public function isInStep(string $step): bool
    {
        return $this->currentStep === $step;
    }

    public function isTimerActive(): bool
    {
        if (!$this->timerStartedAt || !$this->timerDuration) {
            return false;
        }
        
        $now = new \DateTime();
        $elapsed = $now->getTimestamp() - $this->timerStartedAt->getTimestamp();
        
        return $elapsed < ($this->timerDuration * 60); // duration is in minutes
    }

    public function getTimerRemainingSeconds(): int
    {
        if (!$this->timerStartedAt || !$this->timerDuration) {
            return 0;
        }
        
        $now = new \DateTime();
        $elapsed = $now->getTimestamp() - $this->timerStartedAt->getTimestamp();
        $totalSeconds = $this->timerDuration * 60;
        
        return max(0, $totalSeconds - $elapsed);
    }

    /**
     * @return Collection<int, RetrospectiveGroup>
     */
    public function getGroups(): Collection
    {
        return $this->groups;
    }

    public function addGroup(RetrospectiveGroup $group): static
    {
        if (!$this->groups->contains($group)) {
            $this->groups->add($group);
            $group->setRetrospective($this);
        }

        return $this;
    }

    public function removeGroup(RetrospectiveGroup $group): static
    {
        if ($this->groups->removeElement($group)) {
            // set the owning side to null (unless already changed)
            if ($group->getRetrospective() === $this) {
                $group->setRetrospective(null);
            }
        }

        return $this;
    }

    public function getVoteNumbers(): ?int
    {
        return $this->voteNumbers;
    }

    public function setVoteNumbers(int $voteNumbers): static
    {
        $this->voteNumbers = $voteNumbers;
        return $this;
    }
}
