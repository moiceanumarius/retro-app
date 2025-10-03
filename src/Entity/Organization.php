<?php

namespace App\Entity;

use App\Repository\OrganizationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Organization Entity
 * 
 * Reprezentă o organizație în sistemul RetroApp. Organizațiile sunt create și administrate
 * de userii cu rolul ADMIN și pot conține membri cu roluri mai mari decât MEMBER.
 * 
 * Funcționalități:
 * - Organizațiile permit gruparea userilor pentru management centralizat
 * - Un utilizator poate avea roluri în multiple organizații
 * - Cand un MEMBER este invitat în echipă, el este adăugat automat la organizația owner-ului
 * 
 * Relații:
 * - ManyToOne cu User (owner - cel care a creat organizația)
 * - OneToMany cu OrganizationMember (membrii organizației)
 * - OneToMany cu Team (echipele aparținând acestei organizații)
 */
#[ORM\Entity(repositoryClass: OrganizationRepository::class)]
#[ORM\Table(name: 'organizations')]
class Organization
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Numele organizației
     * Ex: "Acme Corporation", "Department Development", etc.
     */
    #[ORM\Column(length: 255)]
    private ?string $name = null;

    /**
     * Descrierea organizației (opțional)
     * Poate conține informații despre scopul și scopurile organizației
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /**
     * Data și ora când organizația a fost creată
     */
    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    /**
     * Data și ora ultimei modificări
     */
    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * Owner-ul organizației - userul care a creat/o deține
     * Acesta are dreptul să administreze organizația și să adauge elimine membri
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $owner = null;

    /**
     * Membrii organizației
     * Colecție de OrganizationMember entities care definesc relația User-Organization
     */
    #[ORM\OneToMany(targetEntity: OrganizationMember::class, mappedBy: 'organization', cascade: ['persist', 'remove'])]
    private Collection $organizationMembers;

    /**
     * Echipele care aparțin acestei organizații
     * Când un owner de echipă este membru al organizației, echipa lui aparține organizației
     */
    #[ORM\OneToMany(mappedBy: 'organization', targetEntity: Team::class, cascade: ['persist'])]
    private Collection $teams;

    /**
     * Status al organizației (activă/inactivă)
     * Organizațiile inactive nu sunt vizibile în interfața normală
     */
    #[ORM\Column]
    private bool $isActive = true;

    public function __construct()
    {
        $this->organizationMembers = new ArrayCollection();
        $this->teams = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Setează numele organizației și actualizează timestamp-ul
     */
    public function setName(string $name): static
    {
        $this->name = $name;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * Setează descrierea organizației și actualizează timestamp-ul
     */
    public function setDescription(?string $description): static
    {
        $this->description = $description;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    /**
     * Setează owner-ul organizației și actualizează timestamp-ul
     */
    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    /**
     * @return Collection<int, OrganizationMember>
     */
    public function getOrganizationMembers(): Collection
    {
        return $this->organizationMembers;
    }

    /**
     * Adaugă un membru în organizație
     */
    public function addOrganizationMember(OrganizationMember $organizationMember): static
    {
        if (!$this->organizationMembers->contains($organizationMember)) {
            $this->organizationMembers->add($organizationMember);
            $organizationMember->setOrganization($this);
        }
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    /**
     * Elimină un membru din organizație
     */
    public function removeOrganizationMember(OrganizationMember $organizationMember): static
    {
        if ($this->organizationMembers->removeElement($organizationMember)) {
            // Set the owning side to null (unless already changed)
            if ($organizationMember->getOrganization() === $this) {
                $organizationMember->setOrganization(null);
            }
        }
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    /**
     * @return Collection<int, Team>
     */
    public function getTeams(): Collection
    {
        return $this->teams;
    }

    /**
     * Adaugă o echipă la organizație
     */
    public function addTeam(Team $team): static
    {
        if (!$this->teams->contains($team)) {
            $this->teams->add($team);
            $team->setOrganization($this);
        }
        return $this;
    }

    /**
     * Elimină o echipă din organizație
     */
    public function removeTeam(Team $team): static
    {
        if ($this->teams->removeElement($team)) {
            // Set the owning side to null (unless already changed)
            if ($team->getOrganization() === $this) {
                $team->setOrganization(null);
            }
        }
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    /**
     * Setează statusul activ/inactiv și actualizează timestamp-ul
     */
    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    /**
     * Returnează membrii activi ai organizației
     * @return Collection<int, OrganizationMember>
     */
    public function getActiveMembers(): Collection
    {
        return $this->organizationMembers->filter(function(OrganizationMember $member) {
            return $member->isActive();
        });
    }

    /**
     * Returnează numărul de membri activi
     */
    public function getMemberCount(): int
    {
        return $this->getActiveMembers()->count();
    }

    /**
     * Verifică dacă un user este membru al organizației
     */
    public function hasMember(User $user): bool
    {
        foreach ($this->getActiveMembers() as $member) {
            if ($member->getUser() === $user) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returnează OrganizationMember pentru un user dat
     */
    public function getMemberByUser(User $user): ?OrganizationMember
    {
        foreach ($this->getActiveMembers() as $member) {
            if ($member->getUser() === $user) {
                return $member;
            }
        }
        return null;
    }

    /**
     * Returnează numele organizației pentru afișare
     */
    public function __toString(): string
    {
        return $this->name ?? 'Unnamed Organization';
    }
}
