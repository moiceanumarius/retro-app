/**
 * ConnectedUsersManager - Unified class for managing connected users display
 * Replaces multiple duplicate functions in retrospective-board.js
 */
class ConnectedUsersManager {
    constructor(retrospectiveId, teamOwnerId) {
        this.retrospectiveId = retrospectiveId;
        this.teamOwnerId = teamOwnerId;
        this.container = document.getElementById('connectedUsers');
        
        if (!this.container) {
            console.error('connectedUsers container not found');
            return;
        }
    }

    /**
     * Main method to update connected users display
     * Replaces: updateConnectedUsers, handleConnectedUsersUpdated, handleUserJoined, handleUserLeft, etc.
     */
    updateUsers(users) {
        if (!this.container) {
            console.error('connectedUsers container not found');
            return;
        }

        console.log('ConnectedUsersManager: Received users:', users);

        // Get current user ID from window object
        const currentUserId = window.user ? window.user.id : null;
        
        console.log('ConnectedUsersManager: Current user ID:', currentUserId);
        console.log('ConnectedUsersManager: All users received:', users.map(u => ({id: u.id, name: u.firstName + ' ' + u.lastName, roles: u.roles})));

        // Store existing timer-liked states before recreating elements
        const existingTimerLikedStates = this.getExistingTimerLikedStates();

        // Clear all users from container
        this.container.innerHTML = '';

        // Add all users in the order received from backend (already sorted)
        users.forEach(user => {
            console.log('ConnectedUsersManager: Processing user:', user);
            
            const userElement = this.createUserElement(user);
            
            // Mark as current user if it's the logged-in user
            if (user.id == currentUserId) {
                userElement.classList.add('current-user');
                // Update status to "You" for current user
                const statusElement = userElement.querySelector('.user-status');
                if (statusElement) {
                    statusElement.textContent = 'You';
                }
            }
            
            // Restore timer-liked state if it existed before
            if (existingTimerLikedStates[user.id]) {
                userElement.classList.add('timer-liked');
            }
            
            this.container.appendChild(userElement);
        });

        // Update the count in the title
        this.updateUsersCount(users.length);

        console.log(`Updated connected users: ${users.length} users displayed`);
    }

    /**
     * Update the count of connected users in the title
     */
    updateUsersCount(count) {
        const titleElement = document.querySelector('.nav-section-title');
        if (titleElement) {
            // Update the text content to include the new count
            const icon = titleElement.querySelector('.nav-icon');
            if (icon) {
                titleElement.innerHTML = `
                    <svg class="nav-icon" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zM18 8a2 2 0 11-4 0 2 2 0 014 0zM14 15a4 4 0 00-8 0v3h8v-3z"></path>
                    </svg>
                    Users (${count})
                `;
            }
        }
    }

    /**
     * Create a user element with proper icons and styling
     */
    createUserElement(user) {
        const userDiv = document.createElement('div');
        userDiv.className = 'user-item';
        userDiv.dataset.userId = user.id;

        // Create avatar
        const avatar = this.createAvatar(user);

        // Determine user type and create appropriate content
        const isOwner = user.id == this.teamOwnerId;
        
        console.log(`ConnectedUsersManager: User ${user.firstName} ${user.lastName} - isOwner: ${isOwner} (teamOwnerId: ${this.teamOwnerId})`);
        
        let iconHtml = '';
        let userStatus = 'Online';
        
        // Priority: Owner first, then others
        if (isOwner) {
            iconHtml = `<svg class="crown-icon" width="14" height="14" viewBox="0 0 24 24" fill="currentColor" title="Team Owner">
                <path d="M5 16L3 5l5.5 3L12 4l3.5 4L21 5l-2 11H5zm2.7-1h8.6l.9-6.4-2.1 1.4L12 7l-3.1 2.4L6.8 8.6L7.7 15z"/>
            </svg>`;
            userStatus = 'Owner';
        }
        
        const userName = iconHtml ? 
            `${iconHtml}${user.firstName} ${user.lastName}` : 
            `${user.firstName} ${user.lastName}`;

        userDiv.innerHTML = `
            <div class="user-avatar">
                ${avatar}
            </div>
            <div class="user-info">
                <div class="user-name">${userName}</div>
                <div class="user-status online">${userStatus}</div>
            </div>
        `;

        return userDiv;
    }

    /**
     * Create avatar element for user
     */
    createAvatar(user) {
        if (user.avatar) {
            return `<img src="/uploads/avatars/${user.avatar}" alt="${user.firstName}">`;
        } else {
            return `<div class="avatar-placeholder">${user.firstName.charAt(0).toUpperCase()}</div>`;
        }
    }

    /**
     * Get existing timer-liked states before recreating elements
     */
    getExistingTimerLikedStates() {
        const existingTimerLikedStates = {};
        const existingUsers = this.container.querySelectorAll('.user-item:not(.current-user)');
        
        existingUsers.forEach(user => {
            const userId = user.dataset.userId;
            if (user.classList.contains('timer-liked')) {
                existingTimerLikedStates[userId] = true;
            }
        });
        
        return existingTimerLikedStates;
    }


    /**
     * Handle timer like states from heartbeat
     */
    handleTimerLikeStates(timerLikeStates) {
        if (!timerLikeStates) return;

        // Remove timer-liked class from all users first
        const allLikedElements = this.container.querySelectorAll('.timer-liked');
        allLikedElements.forEach(element => {
            element.classList.remove('timer-liked');
        });

        // Add timer-liked class to users who have liked the timer
        Object.keys(timerLikeStates).forEach(userId => {
            const userElement = this.container.querySelector(`[data-user-id="${userId}"]`);
            if (userElement) {
                userElement.classList.add('timer-liked');
            }
        });
    }

    /**
     * Clear all timer like states
     */
    clearAllTimerLikeStates() {
        const allLikedElements = this.container.querySelectorAll('.timer-liked');
        allLikedElements.forEach(element => {
            element.classList.remove('timer-liked');
        });
    }
}

// Export for global use
window.ConnectedUsersManager = ConnectedUsersManager;
