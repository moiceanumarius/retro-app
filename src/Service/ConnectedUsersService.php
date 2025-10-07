<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\Retrospective;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

class ConnectedUsersService
{
    private const USER_TIMEOUT = 10800; // 3 hours in seconds

    public function __construct(
        private EntityManagerInterface $entityManager,
        private HubInterface $hub
    ) {}

    /**
     * Get all connected users for a retrospective, sorted by priority
     */
    public function getConnectedUsers(int $retrospectiveId): array
    {
        $file = $this->getFile($retrospectiveId);
        
        if (!file_exists($file)) {
            error_log("ConnectedUsersService: File does not exist: $file");
            return [];
        }
        
        $data = $this->readFile($file);
        error_log("ConnectedUsersService: Raw data from file: " . json_encode($data));
        
        // Filter out inactive users
        $activeUsers = $this->filterActiveUsers($data);
        error_log("ConnectedUsersService: Active users after filtering: " . json_encode($activeUsers));
        
        // Get team owner ID for sorting
        $teamOwnerId = $this->getTeamOwnerId($retrospectiveId);
        error_log("ConnectedUsersService: Team owner ID: " . $teamOwnerId);
        
        // Sort users: admin first, then team owner, then others
        $sortedUsers = $this->sortUsers($activeUsers, $teamOwnerId);
        error_log("ConnectedUsersService: Final sorted users: " . json_encode($sortedUsers));
        
        return $sortedUsers;
    }

    /**
     * Add a user to the connected users list
     */
    public function addUser(int $retrospectiveId, User $user): void
    {
        $file = $this->getFile($retrospectiveId);
        $data = $this->readFile($file);
        
        $data[$user->getId()] = [
            'id' => $user->getId(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'email' => $user->getEmail(),
            'avatar' => $user->getAvatar(),
            'roles' => $user->getActiveRoles(),
            'lastSeen' => time(),
        ];
        
        // Debug logging
        error_log("Added user to connected users: " . $user->getEmail() . " with roles: " . json_encode($user->getActiveRoles()));
        
        $this->writeFile($file, $data);
    }

    /**
     * Remove a user from the connected users list
     */
    public function removeUser(int $retrospectiveId, User $user): void
    {
        $file = $this->getFile($retrospectiveId);
        
        if (!file_exists($file)) {
            return;
        }
        
        $data = $this->readFile($file);
        unset($data[$user->getId()]);
        
        $this->writeFile($file, $data);
    }

    /**
     * Update user's last seen timestamp
     */
    public function updateLastSeen(int $retrospectiveId, User $user): void
    {
        $file = $this->getFile($retrospectiveId);
        
        if (!file_exists($file)) {
            return;
        }
        
        $data = $this->readFile($file);
        
        if (isset($data[$user->getId()])) {
            $data[$user->getId()]['lastSeen'] = time();
            $data[$user->getId()]['roles'] = $user->getActiveRoles(); // Update roles
            $this->writeFile($file, $data);
        }
    }

    /**
     * Publish connected users update via Mercure
     */
    public function publishUpdate(int $retrospectiveId): void
    {
        try {
            // Get updated connected users
            $connectedUsers = $this->getConnectedUsers($retrospectiveId);
            
            // Get timer like states for all connected users
            $timerLikeStates = $this->getTimerLikeStates($retrospectiveId);
            
            // Publish update to connected-users topic
            $update = new Update(
                "retrospective/{$retrospectiveId}/connected-users",
                json_encode([
                    'type' => 'connected_users_updated',
                    'users' => $connectedUsers,
                    'timerLikeStates' => $timerLikeStates
                ])
            );
            $this->hub->publish($update);
            
            // Also broadcast to general retrospective topic
            $update2 = new Update(
                "retrospective/{$retrospectiveId}",
                json_encode([
                    'type' => 'connected_users_updated',
                    'users' => $connectedUsers,
                    'timerLikeStates' => $timerLikeStates
                ])
            );
            $this->hub->publish($update2);
        } catch (\Exception $e) {
            // Log error but don't break the main flow
            error_log('Error publishing connected users update: ' . $e->getMessage());
        }
    }

    /**
     * Get file path for storing connected users data
     */
    private function getFile(int $retrospectiveId): string
    {
        return sys_get_temp_dir() . "/retrospective_{$retrospectiveId}_users.json";
    }

    /**
     * Read data from file
     */
    private function readFile(string $file): array
    {
        if (!file_exists($file)) {
            return [];
        }
        
        $data = json_decode(file_get_contents($file), true);
        return $data ?: [];
    }

    /**
     * Write data to file
     */
    private function writeFile(string $file, array $data): void
    {
        file_put_contents($file, json_encode($data));
    }

    /**
     * Filter out users that haven't been active recently
     */
    private function filterActiveUsers(array $data): array
    {
        $activeUsers = [];
        $now = time();
        
        foreach ($data as $userId => $userData) {
            $timeDiff = $now - $userData['lastSeen'];
            if ($timeDiff < self::USER_TIMEOUT) {
                $activeUsers[] = $userData;
            }
        }
        
        return $activeUsers;
    }

    /**
     * Get team owner ID for sorting
     */
    private function getTeamOwnerId(int $retrospectiveId): ?int
    {
        $retrospective = $this->entityManager->getRepository(Retrospective::class)->find($retrospectiveId);
        return $retrospective ? $retrospective->getTeam()->getOwner()->getId() : null;
    }

    /**
     * Sort users: team owner first, then others
     */
    private function sortUsers(array $users, ?int $teamOwnerId): array
    {
        // Debug logging
        error_log("=== SORTING DEBUG ===");
        error_log("Team Owner ID: " . ($teamOwnerId ?? 'NULL'));
        error_log("Users before sorting: " . json_encode(array_map(function($u) use ($teamOwnerId) { 
            return ['id' => $u['id'], 'email' => $u['email'], 'isOwner' => ($u['id'] == $teamOwnerId)]; 
        }, $users)));
        
        usort($users, function($a, $b) use ($teamOwnerId) {
            $aIsOwner = $a['id'] == $teamOwnerId;
            $bIsOwner = $b['id'] == $teamOwnerId;
            
            error_log("Comparing: {$a['email']} (isOwner: " . ($aIsOwner ? 'true' : 'false') . ") vs {$b['email']} (isOwner: " . ($bIsOwner ? 'true' : 'false') . ")");
            
            // Owner first, then others
            if ($aIsOwner && !$bIsOwner) {
                error_log("Result: {$a['email']} comes first (owner)");
                return -1;
            }
            if (!$aIsOwner && $bIsOwner) {
                error_log("Result: {$b['email']} comes first (owner)");
                return 1;
            }
            
            error_log("Result: no change");
            return 0;
        });
        
        // Debug logging
        error_log("Users after sorting: " . json_encode(array_map(function($u) use ($teamOwnerId) { 
            return ['id' => $u['id'], 'email' => $u['email'], 'isOwner' => ($u['id'] == $teamOwnerId)]; 
        }, $users)));
        error_log("=== END SORTING DEBUG ===");
        
        return $users;
    }

    /**
     * Get timer like states for all connected users
     */
    private function getTimerLikeStates(int $retrospectiveId): array
    {
        $timerLikeRepo = $this->entityManager->getRepository(\App\Entity\TimerLike::class);
        $retrospective = $this->entityManager->getRepository(Retrospective::class)->find($retrospectiveId);
        
        if (!$retrospective) {
            return [];
        }
        
        $timerLikes = $timerLikeRepo->findByRetrospective($retrospective);
        $timerLikeStates = [];
        
        foreach ($timerLikes as $timerLike) {
            if ($timerLike->isLiked()) {
                $timerLikeStates[$timerLike->getUser()->getId()] = [
                    'userId' => $timerLike->getUser()->getId(),
                    'userName' => $timerLike->getUser()->getFullName(),
                    'isLiked' => true,
                    'timestamp' => $timerLike->getUpdatedAt()->getTimestamp()
                ];
            }
        }
        
        return $timerLikeStates;
    }
}
