<?php

namespace App\Entity;

use App\Repository\OrganizationMemberRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * OrganizationMember Entity
 * 
 * Reprezentă relația Many-to-Many între User și Organization.
 * Fiecare înregistrare corespunde unui user care este membru al unei organizații.
 * 
 * Scopul:
 * - Permite userilor să facă parte din multiple organizații
 * - Gestionează rolul unui user în cadrul unei organizații specifice
 * - Păstrează istorical invitației și activității membrului
 * 
 * Caracteristici:
 * - Status activ/inactiv pentru a permite soft delete
 * - Timp de expirare pentru membri temporari
 * - Istoric despre cine l-a invitat
 * - Note opționale pentru context suplimentar
 */
#[ORM\Entity(repositoryClass: OrganizationMemberRepository::class)]
#[ORM\Table(name: 'organization_members')]
#[ORM\UniqueConstraint(name: 'unique_user_organization', columns: ['user_id', 'organization_id'])]
class OrganizationMember
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Organizația din care face parte membru
     * Relația Many-to-One: mulți membri → o organizație
     */
    #[ORM\ManyToOne(targetEntity: Organization::class, inversedBy: 'organizationMembers')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Organization $organization = null;

    /**
     * Userul care este membru al organizației
     * Relația Many-to-One: mulți membri (din organizații diferite) → un user
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    /**
     * Data când userul a devenit membru al organizației
     */
    #[ORM\Column]
    private ?\DateTimeImmutable $joinedAt = null;

    /**
     * Data când userul a părăsit organizația
     * Null înseamnă că membru este încă activ
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $leftAt = null;

    /**
     * Status al membrului în organizație (activ/inactiv)
     * Membrii inactivi sunt considerați "șterși" din punct de vedere funcțional
     */
    #[ORM\Column]
    private bool $isActive = true;

    /**
     * Rolul membrului în organizație
     * Ex: "Admin", "Member", "Observer", etc.
     * Diferit de sistemul de roluri global ROLE_ADMIN/FACILITATOR/etc
     */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $role = null;

    /**
     * Userul care a invitat/făcut membru pe acest user în organizație
     * Trebuie să fie cel puțin ROLE_ADMIN pentru a putea invita membri
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $invitedBy = null;

    /**
     * Note suplimentare despre prezența membrului în organizație
     * Poate conține contexte, responsabilități specifice, etc.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    /**
     * Data de expirare a membrului în organizație (opțional)
     * Dacă este setată și data actuală > expiresAt, membru trebuie să fie considerat expirat
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    public function __construct()
    {
        $this->joinedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrganization(): ?Organization
    {
        return $this->organization;
    }

    public function setOrganization($organization): static
    {
        $this->organization = $organization;
        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser($user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getJoinedAt(): ?\DateTimeImmutable
    {
        return $this->joinedAt;
    }

    public function setJoinedAt(\DateTimeImmutable $joinedAt): static
    {
        $this->joinedAt = $joinedAt;
        return $this;
    }

    public function getLeftAt(): ?\DateTimeImmutable
    {
        return $this->leftAt;
    }

    public function setLeftAt(?\DateTimeImmutable $leftAt): static
    {
        $this->leftAt = $leftAt;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function setRole(?string $role): static
    {
        $this->role = $role;
        return $this;
    }

    public function getInvitedBy(): ?User
    {
        return $this->invitedBy;
    }

    public function setInvitedBy(?User $invitedBy): static
    {
        $this->invitedBy = $invitedBy;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;
        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }

    /**
     * Verifică dacă membrul este încă valabil (activ și neexpatiat)
     */
    public function isExpired(): bool
    {
        if (!$this->isActive()) {
            return true;
        }

        if ($this->expiresAt !== null && $this->expiresAt < new \DateTimeImmutable()) {
            return true;
        }

        return false;
    }

    /**
     * Verifică dacă membrul este complet valid (activ, neexpatiat, și încă în organizație)
     */
    public function isValid(): bool
    {
        return $this->isActive() && !$this->isExpired() && $this->leftAt === null;
    }

    /**
     * Marchează membrul ca lăsat organizația
     */
    public function leaveOrganization(): static
    {
        $this->leftAt = new \DateTimeImmutable();
        $this->isActive = false;
        return $this;
    }

    /**
     * Reactivează membrul în organizație
     */
    public function rejoinOrganization(): static
    {
        $this->leftAt = null;
        $this->isActive = true;
        $this->joinedAt = new \DateTimeImmutable();
        return $this;
    }

    /**
     * Extinde perioada de membership cu un număr de zile
     */
    public function extendMembership(int $days): static
    {
        $currentExpiry = $this->expiresAt ?? new \DateTimeImmutable();
        $this->expiresAt = $currentExpiry->add(new \DateInterval("P{$days}D"));
        return $this;
    }

    /**
     * Returnează numele complet al userului pentru afișare
     */
    public function getUserDisplayName(): string
    {
        if (!$this->user) {
            return 'Unknown User';
        }
        
        return $this->user->getFirstName() . ' ' . $this->user->getLastName();
    }

    /**
     * Returnează email-ul userului pentru afișare
     */
    public function getUserEmail(): string
    {
        return $this->user ? $this->user->getEmail() : '';
    }

    /**
     * Returnează informațiile membrei pentru debug/log
     */
    public function __toString(): string
    {
        $orgName = $this->organization ? $this->organization->getName() : 'Unknown Organization';
        $userName = $this->getUserDisplayName();
        
        return "{$userName} in {$orgName}" . ($this->role ? " ({$this->role})" : '');
    }
}
