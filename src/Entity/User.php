<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'There is already an account with this email')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private ?string $email = null;

    /**
     * @var list<string> The user roles
     */
    #[ORM\Column]
    private array $roles = [];

    /**
     * @var string The hashed password
     */
    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(length: 255)]
    private ?string $firstName = null;

    #[ORM\Column(length: 255)]
    private ?string $lastName = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column]
    private bool $isVerified = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $avatar = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $bio = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $timezone = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $language = null;

    #[ORM\Column(type: 'boolean')]
    private bool $emailNotifications = true;

    #[ORM\Column(type: 'boolean')]
    private bool $pushNotifications = true;

    #[ORM\Column(type: 'boolean')]
    private bool $weeklyDigest = true;

    #[ORM\OneToMany(targetEntity: UserRole::class, mappedBy: 'user', cascade: ['persist', 'remove'])]
    private Collection $userRoles;

    /**
     * Organizațiile deținute de acest user
     * Relația One-to-Many: un user poate să dețină multiple organizații
     */
    #[ORM\OneToMany(targetEntity: Organization::class, mappedBy: 'owner', cascade: ['persist'])]
    private Collection $ownedOrganizations;

    /**
     * Membrii organizațiilor pentru acest user
     * Relația One-to-Many: un user poate fi membru în multiple organizații
     */
    #[ORM\OneToMany(targetEntity: OrganizationMember::class, mappedBy: 'user', cascade: ['persist', 'remove'])]
    private Collection $organizationMemberships;

    public function __construct()
    {
        $this->roles = [];
        $this->userRoles = new ArrayCollection();
        $this->ownedOrganizations = new ArrayCollection();
        $this->organizationMemberships = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        // Get roles from UserRole entities including inherited ones
        $roles = $this->getAllRolesIncludingInherited();
        
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * Ensure the session doesn't contain actual password hashes by CRC32C-hashing them, as supported since Symfony 7.3.
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0password"] = hash('crc32c', $this->password);

        return $data;
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
        // @deprecated, to be removed when upgrading to Symfony 8
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): static
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): static
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getFullName(): string
    {
        return $this->firstName . ' ' . $this->lastName;
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

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): static
    {
        $this->isVerified = $isVerified;

        return $this;
    }

    public function getAvatar(): ?string
    {
        return $this->avatar;
    }

    public function setAvatar(?string $avatar): static
    {
        $this->avatar = $avatar;

        return $this;
    }

    public function getBio(): ?string
    {
        return $this->bio;
    }

    public function setBio(?string $bio): static
    {
        $this->bio = $bio;

        return $this;
    }

    public function getTimezone(): ?string
    {
        return $this->timezone;
    }

    public function setTimezone(?string $timezone): static
    {
        $this->timezone = $timezone;

        return $this;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function setLanguage(?string $language): static
    {
        $this->language = $language;

        return $this;
    }

    public function isEmailNotifications(): bool
    {
        return $this->emailNotifications;
    }

    public function setEmailNotifications(bool $emailNotifications): static
    {
        $this->emailNotifications = $emailNotifications;

        return $this;
    }

    public function isPushNotifications(): bool
    {
        return $this->pushNotifications;
    }

    public function setPushNotifications(bool $pushNotifications): static
    {
        $this->pushNotifications = $pushNotifications;

        return $this;
    }

    public function isWeeklyDigest(): bool
    {
        return $this->weeklyDigest;
    }

    public function setWeeklyDigest(bool $weeklyDigest): static
    {
        $this->weeklyDigest = $weeklyDigest;

        return $this;
    }

    /**
     * @return Collection<int, UserRole>
     */
    public function getUserRoles(): Collection
    {
        return $this->userRoles;
    }

    public function addUserRole(UserRole $userRole): static
    {
        if (!$this->userRoles->contains($userRole)) {
            $this->userRoles->add($userRole);
            $userRole->setUser($this);
        }

        return $this;
    }

    public function removeUserRole(UserRole $userRole): static
    {
        if ($this->userRoles->removeElement($userRole)) {
            // set the owning side to null (unless already changed)
            if ($userRole->getUser() === $this) {
                $userRole->setUser(null);
            }
        }

        return $this;
    }

    /**
     * Get active roles for the user
     * @return array
     */
    public function getActiveRoles(): array
    {
        $activeRoles = [];
        foreach ($this->userRoles as $userRole) {
            if ($userRole->isActive() && !$userRole->isExpired()) {
                $activeRoles[] = $userRole->getRole()->getCode();
            }
        }
        return $activeRoles;
    }

    /**
     * Get all roles including inherited ones based on hierarchy
     * Hierarchy: Administrator > Team Lead > Facilitator > Member
     * @return array
     */
    public function getAllRolesIncludingInherited(): array
    {
        $activeRoles = $this->getActiveRoles();
        $allRoles = $activeRoles;

        // Define role hierarchy (higher roles include lower ones)
        $hierarchy = [
            'ROLE_ADMIN' => ['ROLE_TEAM_LEAD', 'ROLE_FACILITATOR', 'ROLE_MEMBER'],
            'ROLE_TEAM_LEAD' => ['ROLE_FACILITATOR', 'ROLE_MEMBER'],
            'ROLE_FACILITATOR' => ['ROLE_MEMBER'],
            'ROLE_MEMBER' => []
        ];

        // Add inherited roles
        foreach ($activeRoles as $role) {
            if (isset($hierarchy[$role])) {
                $allRoles = array_merge($allRoles, $hierarchy[$role]);
            }
        }

        return array_unique($allRoles);
    }

    /**
     * Check if user has a specific role (including inherited)
     */
    public function hasRole(string $roleCode): bool
    {
        return in_array($roleCode, $this->getAllRolesIncludingInherited());
    }

    /**
     * Check if user has any of the specified roles (including inherited)
     */
    public function hasAnyRole(array $roleCodes): bool
    {
        return !empty(array_intersect($roleCodes, $this->getAllRolesIncludingInherited()));
    }

    /**
     * Check if user has a specific role directly assigned (not inherited)
     */
    public function hasDirectRole(string $roleCode): bool
    {
        return in_array($roleCode, $this->getActiveRoles());
    }

    /**
     * @return Collection<int, Organization>
     */
    public function getOwnedOrganizations(): Collection
    {
        return $this->ownedOrganizations;
    }

    /**
     * Adaugă o organizație deținută de user
     */
    public function addOwnedOrganization(Organization $ownedOrganization): static
    {
        if (!$this->ownedOrganizations->contains($ownedOrganization)) {
            $this->ownedOrganizations->add($ownedOrganization);
            $ownedOrganization->setOwner($this);
        }

        return $this;
    }

    /**
     * Elimină o organizație din cele deținute
     */
    public function removeOwnedOrganization(Organization $ownedOrganization): static
    {
        if ($this->ownedOrganizations->removeElement($ownedOrganization)) {
            // set the owning side to null (unless already changed)
            if ($ownedOrganization->getOwner() === $this) {
                $ownedOrganization->setOwner(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, OrganizationMember>
     */
    public function getOrganizationMemberships(): Collection
    {
        return $this->organizationMemberships;
    }

    /**
     * Adaugă o membră organizațională pentru user
     */
    public function addOrganizationMembership(OrganizationMember $organizationMembership): static
    {
        if (!$this->organizationMemberships->contains($organizationMembership)) {
            $this->organizationMemberships->add($organizationMembership);
            $organizationMembership->setUser($this);
        }

        return $this;
    }

    /**
     * Elimină o membră organizațională pentru user
     */
    public function removeOrganizationMembership(OrganizationMember $organizationMembership): static
    {
        if ($this->organizationMemberships->removeElement($organizationMembership)) {
            // set the owning side to null (unless already changed)
            if ($organizationMembership->getUser() === $this) {
                $organizationMembership->setUser(null);
            }
        }

        return $this;
    }

    /**
     * Returnează organizațiile active din care face parte userul
     * @return Collection<int, OrganizationMember>
     */
    public function getActiveOrganizationMemberships(): Collection
    {
        return $this->organizationMemberships->filter(function(OrganizationMember $membership) {
            return $membership->isValid();
        });
    }

    /**
     * Verifică dacă userul este membru al unei organizații specifice
     */
    public function isMemberOfOrganization(Organization $organization): bool
    {
        return $organization->hasMember($this);
    }

    /**
     * Returnează organizația din care face parte userul (dacă este doar una)
     * Pentru cazuri unde ne putem bază că userul face parte doar dintr-o organizație
     */
    public function getOrganizationMembership(): ?OrganizationMember
    {
        $activeMemberships = $this->getActiveOrganizationMemberships();
        return $activeMemberships->isEmpty() ? null : $activeMemberships->first();
    }
}
