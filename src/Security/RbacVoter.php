<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class RbacVoter extends Voter
{
    public const VIEW = 'view';
    public const CREATE = 'create';
    public const EDIT = 'edit';
    public const DELETE = 'delete';
    public const FACILITATE = 'facilitate';
    public const PARTICIPATE = 'participate';
    public const MANAGE = 'manage';

    protected function supports(string $attribute, mixed $subject): bool
    {
        // If the attribute isn't one we support, return false
        if (!in_array($attribute, [self::VIEW, self::CREATE, self::EDIT, self::DELETE, self::FACILITATE, self::PARTICIPATE, self::MANAGE])) {
            return false;
        }

        // Only vote on objects that have a resource type
        if (is_string($subject)) {
            return in_array($subject, ['team', 'retrospective', 'user', 'organization', 'system']);
        }

        return false;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            // The user must be logged in; if not, deny access
            return false;
        }

        // Admin has access to everything
        if ($user->hasRole('ROLE_ADMIN')) {
            return true;
        }

        // Check permissions based on resource and action
        switch ($subject) {
            case 'team':
                return $this->checkTeamPermissions($user, $attribute);
            case 'retrospective':
                return $this->checkRetrospectivePermissions($user, $attribute);
            case 'user':
                return $this->checkUserPermissions($user, $attribute);
            case 'organization':
                return $this->checkOrganizationPermissions($user, $attribute);
            case 'system':
                return $this->checkSystemPermissions($user, $attribute);
        }

        return false;
    }

    private function checkTeamPermissions(User $user, string $attribute): bool
    {
        switch ($attribute) {
            case self::VIEW:
                return $user->hasRole('ROLE_MEMBER'); // All roles inherit from MEMBER
            case self::CREATE:
            case self::EDIT:
            case self::MANAGE:
                return $user->hasRole('ROLE_FACILITATOR'); // FACILITATOR and above
            case self::DELETE:
                return $user->hasRole('ROLE_ADMIN'); // Only ADMIN
        }

        return false;
    }

    private function checkRetrospectivePermissions(User $user, string $attribute): bool
    {
        switch ($attribute) {
            case self::VIEW:
            case self::PARTICIPATE:
                return $user->hasRole('ROLE_MEMBER'); // All roles inherit from MEMBER
            case self::CREATE:
            case self::EDIT:
            case self::FACILITATE:
                return $user->hasRole('ROLE_FACILITATOR'); // FACILITATOR and above
            case self::DELETE:
                return $user->hasRole('ROLE_ADMIN'); // Only ADMIN
        }

        return false;
    }

    private function checkUserPermissions(User $user, string $attribute): bool
    {
        switch ($attribute) {
            case self::VIEW:
                return $user->hasRole('ROLE_MEMBER'); // All roles inherit from MEMBER
            case self::CREATE:
            case self::MANAGE:
                return $user->hasRole('ROLE_FACILITATOR'); // FACILITATOR and above
        }

        return false;
    }

    private function checkOrganizationPermissions(User $user, string $attribute): bool
    {
        switch ($attribute) {
            case self::VIEW:
                return $user->hasRole('ROLE_MEMBER'); // All roles inherit from MEMBER
            case self::MANAGE:
                return $user->hasRole('ROLE_ADMIN'); // Only ADMIN
        }

        return false;
    }

    private function checkSystemPermissions(User $user, string $attribute): bool
    {
        // Only admin can access system functions
        return $user->hasRole('ROLE_ADMIN');
    }
}
