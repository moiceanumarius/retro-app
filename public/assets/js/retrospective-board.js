// Retrospective Board JavaScript
class RetrospectiveBoard {
    constructor() {
        this.retrospectiveId = window.retrospectiveId;
        this.isFacilitator = window.isFacilitator;
        this.user = window.user;
        this.timerInterval = null;
        this.timerEndTime = null;
        this.eventSource = null;
        this.timerManuallyStopped = false;
        this.reconnectTimeout = null;
        this.isReconnecting = false;
        this.timerExpiredMessageShown = false;
        this.isDragging = false;
        this.dragOffset = { x: 0, y: 0 };
        this.reviewItems = [];
        this.reviewGroups = [];
        this.draggedItem = null;
        this.dragStartPosition = { x: 0, y: 0 };
        
        // Voting system
        this.userVotes = {}; // {itemId: voteCount} or {groupId: voteCount}
        this.totalVotes = 0;
        this.maxTotalVotes = window.retrospectiveData ? window.retrospectiveData.voteNumbers : 10;
        this.maxVotesPerItem = 2;
        this.votingActive = false;
        
        // Connected users manager
        this.connectedUsersManager = new ConnectedUsersManager(
            this.retrospectiveId,
            window.retrospectiveData ? window.retrospectiveData.teamOwnerId : null
        );
        
        // Store global reference for cleanup
        window.retrospectiveBoard = this;
        
        this.init();
    }
    
    init() {
        this.bindEvents();
        this.joinRetrospective();
        this.checkInitialTimerStatus();
        this.connectToMercure();
        this.addEventListenersToExistingPosts();
        this.initDragAndDrop();
        
        // Initialize review phase if we're in review step
        if (this.isInReviewStep()) {
            this.initReviewPhase();
        }
        
        // Timer like states will be restored via heartbeat WebSocket updates only
        // No manual restoration to avoid conflicts
        
        // Load user votes if we're in voting phase (to show vote badges)
        if (this.isInDiscussionStep()) {
            console.log('In voting phase, loading user votes for badges...');
            if (typeof this.loadUserVotes === 'function') {
                this.loadUserVotes();
            }
        }
        
        // Initialize card zoom in action phase
        if (this.isInActionStep()) {
            this.initCardZoom();
        }
        
        // Hide timer in review and action phases
        if (this.isInReviewStep() || this.isInActionStep()) {
            const floatingTimer = document.getElementById('floatingTimer');
            if (floatingTimer) {
                floatingTimer.style.display = 'none';
            }
        }
        
        // Start polling for connected users to update sidebar
        this.startUserConnectivityPolling();
    }
    
    bindEvents() {
        // Timer controls
        const startTimerBtn = document.getElementById('startTimerBtn');
        if (startTimerBtn) {
            startTimerBtn.addEventListener('click', () => {
                this.startTimer();
            });
        }

        // Stop timer button
        const stopTimerBtn = document.getElementById('stopTimerBtn');
        if (stopTimerBtn) {
            stopTimerBtn.addEventListener('click', () => {
                this.stopTimer();
            });
        }

        // Like timer button
        const likeTimerBtn = document.getElementById('likeTimerBtn');
        if (likeTimerBtn) {
            likeTimerBtn.addEventListener('click', () => {
                this.likeTimer();
            });
        }
        
        // Next step button
        const nextStepBtn = document.getElementById('nextStepBtn');
        if (nextStepBtn) {
            nextStepBtn.addEventListener('click', () => this.nextStep());
        }
        
        // Complete retrospective button
        const completeRetrospectiveBtn = document.getElementById('completeRetrospectiveBtn');
        if (completeRetrospectiveBtn) {
            completeRetrospectiveBtn.addEventListener('click', () => this.completeRetrospective());
        }
        
        // Add item buttons
        document.querySelectorAll('.add-item-btn').forEach(btn => {
            btn.addEventListener('click', (e) => this.addItem(e));
        });
        
        // Enter key in textareas
        document.querySelectorAll('.item-input').forEach(input => {
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && e.ctrlKey) {
                    e.preventDefault();
                    this.addItem(e);
                }
            });
        });
        
        // Leave retrospective when page unloads
        window.addEventListener('beforeunload', () => {
            this.leaveRetrospective();
            this.clearTimerLikeState();
        });
        
        // Action form controls
        const showActionFormBtn = document.getElementById('showActionFormBtn');
        if (showActionFormBtn) {
            showActionFormBtn.addEventListener('click', () => this.showActionForm());
        }
        
        const cancelActionBtn = document.getElementById('cancelActionBtn');
        if (cancelActionBtn) {
            cancelActionBtn.addEventListener('click', () => this.hideActionForm());
        }
        
        const addActionForm = document.getElementById('addActionForm');
        if (addActionForm) {
            addActionForm.addEventListener('submit', (e) => this.handleAddAction(e));
        }
    }
    
    async startTimer() {
        const durationInput = document.getElementById('timerDuration');
        const duration = parseInt(durationInput.value);
        
        if (!duration || duration < 1) {
            this.showMessage('Please enter a valid duration', 'error');
            return;
        }
        
        try {
            const response = await fetch(`/retrospectives/${this.retrospectiveId}/start-timer`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                credentials: 'include',
                body: JSON.stringify({ duration: duration })
            });
            
            if (response.ok) {
                const data = await response.json();
                this.timerManuallyStopped = false;
                this.showMessage('Timer started successfully!', 'success');
                this.startTimerDisplay(duration);
                this.showAddItemForms();
            } else {
                const error = await response.json();
                this.showMessage(error.message || 'Failed to start timer', 'error');
            }
        } catch (error) {
            console.error('Error starting timer:', error);
            this.showMessage('Failed to start timer', 'error');
        }
    }

    async stopTimer() {
        try {
            const response = await fetch(`/retrospectives/${this.retrospectiveId}/stop-timer`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                credentials: 'same-origin'
            });
            
            
            if (response.ok) {
                const data = await response.json();
                this.timerManuallyStopped = true;
                this.showMessage('Timer stopped successfully!', 'success');
                this.stopTimerDisplay();
                this.hideAddItemForms();
            } else {
                const error = await response.json();
                this.showMessage(error.message || 'Failed to stop timer', 'error');
            }
        } catch (error) {
            console.error('Error stopping timer:', error);
            this.showMessage('Failed to stop timer', 'error');
        }
    }

    async likeTimer() {
        const likeBtn = document.getElementById('likeTimerBtn');
        if (!likeBtn) return;

        // Toggle liked state
        const isLiked = likeBtn.classList.contains('liked');
        const currentUserId = this.user?.id || window.user?.id;
        
        if (isLiked) {
            likeBtn.classList.remove('liked');
            // Update current user's sidebar background
            this.updateUserSidebarBackground(false);
            // Broadcast to all users that this user unliked
            await this.broadcastTimerLike(false);
        } else {
            likeBtn.classList.add('liked');
            // Update current user's sidebar background
            this.updateUserSidebarBackground(true);
            // Broadcast to all users that this user liked
            await this.broadcastTimerLike(true);
        }

        // Add temporary pulse effect
        likeBtn.style.animation = 'none';
        setTimeout(() => {
            likeBtn.style.animation = 'thumbUp 0.6s ease-in-out';
        }, 10);
    }

    updateUserSidebarBackground(isLiked) {
        // Find current user's element in sidebar by user ID or name
        const currentUserId = this.user?.id || window.user?.id;
        const currentUserName = this.user?.firstName || window.user?.firstName;
        
        // Try to find user element by data attribute or class
        let userElement = null;
        
        // First try to find current user element (which has current-user class)
        userElement = document.querySelector(`.current-user[data-user-id="${currentUserId}"]`);
        
        // If not found, try other selectors
        if (!userElement) {
            const possibleSelectors = [
                `[data-user-id="${currentUserId}"]`,
                `.user-${currentUserId}`,
                `.user-item[data-user="${currentUserId}"]`,
                `.sidebar-user[data-id="${currentUserId}"]`,
                `.connected-user[data-user-id="${currentUserId}"]`
            ];
            
            
            for (const selector of possibleSelectors) {
                userElement = document.querySelector(selector);
                if (userElement) {
                    break;
                }
            }
        } else {
        }
        
        // If still not found, try to find by text content (username)
        if (!userElement && currentUserName) {
            const allUserElements = document.querySelectorAll('.user-item, .sidebar-user, .connected-user, [class*="user"]');
            
            for (const el of allUserElements) {
                if (el.textContent.includes(currentUserName)) {
                    userElement = el;
                    break;
                }
            }
        }
        
        if (userElement) {
            if (isLiked) {
                userElement.classList.add('timer-liked');
            } else {
                userElement.classList.remove('timer-liked');
            }
        }
    }

    async broadcastTimerLike(isLiked) {
        const currentUserId = this.user?.id || window.user?.id;
        const currentUserName = this.user?.firstName || window.user?.firstName;
        
        
        if (!currentUserId) {
            console.error('🔍 DEBUG: Cannot broadcast timer like: User ID not found');
            return;
        }

        try {
            // Get CSRF token (same as other actions)
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            
            const requestBody = {
                isLiked: isLiked,
                userId: currentUserId,
                userName: currentUserName,
                _token: csrfToken
            };
            
            
            // Send via API endpoint (same pattern as other actions)
            const response = await fetch(`/retrospectives/${this.retrospectiveId}/timer-like-update`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                credentials: 'include',
                body: JSON.stringify(requestBody)
            });


            if (response.ok) {
            } else {
                console.error('🔍 DEBUG: Failed to send timer like update:', response.statusText);
            }
        } catch (error) {
            console.error('🔍 DEBUG: Error sending timer like update:', error);
        }
    }
    
    startTimerDisplay(duration) {
        const timerDisplay = document.getElementById('timerDisplay');
        const timerTime = document.getElementById('timerTime');
        const timerControls = document.querySelector('.timer-controls');
        const floatingTimer = document.getElementById('floatingTimer');
        const setTimerLabel = floatingTimer ? floatingTimer.querySelector('.timer-label') : null;
        
        if (timerDisplay && timerTime) {
            // Show floating timer (only if not in action phase)
            if (floatingTimer && this.shouldShowTimer()) {
                floatingTimer.style.display = 'block';
            }
            
            // Hide "Set timer" label
            if (setTimerLabel) {
                setTimerLabel.style.display = 'none';
            }
            
            timerDisplay.style.display = 'flex';
            if (timerControls) {
                timerControls.style.display = 'none';
            }
            
            this.timerEndTime = Date.now() + (duration * 60 * 1000);
            this.updateTimerDisplay();
            
            this.timerInterval = setInterval(() => {
                this.updateTimerDisplay();
            }, 1000);
        }
    }

    startTimerDisplayFromServer(remainingSeconds) {
        const timerDisplay = document.getElementById('timerDisplay');
        const timerTime = document.getElementById('timerTime');
        const timerControls = document.querySelector('.timer-controls');
        const floatingTimer = document.getElementById('floatingTimer');
        const setTimerLabel = floatingTimer ? floatingTimer.querySelector('.timer-label') : null;
        const timerStopped = document.getElementById('timerStopped');
        
        if (timerDisplay && timerTime) {
            // Show floating timer (only if not in action phase)
            if (floatingTimer && this.shouldShowTimer()) {
                floatingTimer.style.display = 'block';
            }
            
            // Hide "Set timer" label
            if (setTimerLabel) {
                setTimerLabel.style.display = 'none';
            }
            
            // Hide "Timer stopped" message
            if (timerStopped) {
                timerStopped.style.display = 'none';
            }
            
            timerDisplay.style.display = 'flex';
            if (timerControls) {
                timerControls.style.display = 'none';
            }
            
            // Use exact remaining seconds from server
            this.timerEndTime = Date.now() + (remainingSeconds * 1000);
            this.updateTimerDisplay();
            
            this.timerInterval = setInterval(() => {
                this.updateTimerDisplay();
            }, 1000);
        }
    }
    
    updateTimerDisplay() {
        const timerTime = document.getElementById('timerTime');
        const timerDisplay = document.getElementById('timerDisplay');
        
        if (!timerTime || !this.timerEndTime) return;
        
        const remaining = Math.max(0, this.timerEndTime - Date.now());
        const minutes = Math.floor(remaining / 60000);
        const seconds = Math.floor((remaining % 60000) / 1000);
        
        const timeString = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
        timerTime.textContent = timeString;
        
        
        // Update display class based on remaining time
        if (remaining <= 60000) { // 1 minute
            timerDisplay.className = 'timer-display danger';
        } else if (remaining <= 300000) { // 5 minutes
            timerDisplay.className = 'timer-display warning';
        } else {
            timerDisplay.className = 'timer-display';
        }
        
        if (remaining === 0) {
            clearInterval(this.timerInterval);
            this.timerInterval = null;
            
            // Show message only once
            if (!this.timerExpiredMessageShown) {
                this.showMessage('Timer expired!', 'error');
                this.timerExpiredMessageShown = true;
            }
            
            // Keep showing 0:00 instead of hiding the timer
            timerTime.textContent = '0:00';
        }
    }
    
    showAddItemForms() {
        document.querySelectorAll('.add-item-form').forEach(form => {
            form.style.display = 'block';
        });
    }

    hideAddItemForms() {
        document.querySelectorAll('.add-item-form').forEach(form => {
            form.style.display = 'none';
        });
    }

    stopTimerDisplay() {
        // Clear timer interval
        if (this.timerInterval) {
            clearInterval(this.timerInterval);
            this.timerInterval = null;
        }
        
        // Hide timer display and show controls for facilitator
        const timerDisplay = document.getElementById('timerDisplay');
        const timerControls = document.querySelector('.timer-controls');
        const floatingTimer = document.getElementById('floatingTimer');
        const setTimerLabel = floatingTimer ? floatingTimer.querySelector('.timer-label') : null;
        const timerStopped = document.getElementById('timerStopped');
        
        if (timerDisplay) {
            timerDisplay.style.display = 'none';
        }
        
        if (timerControls) {
            timerControls.style.display = 'flex';
        }
        
        // Show "Set timer" label again
        if (setTimerLabel) {
            setTimerLabel.style.display = 'block';
        }
        
        // For members: show "Timer stopped" message
        if (timerStopped) {
            if (window.isFacilitator) {
                timerStopped.style.display = 'none';
            } else {
                timerStopped.style.display = 'block';
            }
        }
        
        // Keep floating timer visible for facilitator (but not in action phase)
        if (floatingTimer && window.isFacilitator && this.shouldShowTimer()) {
            floatingTimer.style.display = 'block';
        }
        
        // For members: show floating timer with "Timer stopped" message (but not in action phase)
        if (floatingTimer && !window.isFacilitator && this.shouldShowTimer()) {
            floatingTimer.style.display = 'block';
        }
    }

    initDragAndDrop() {
        const floatingTimer = document.getElementById('floatingTimer');
        if (!floatingTimer) return;

        // Mouse events
        floatingTimer.addEventListener('mousedown', (e) => {
            // Only start drag if clicking on the background, not on interactive elements
            if (e.target === floatingTimer || e.target.classList.contains('timer-label')) {
                this.startDrag(e);
            }
        });

        document.addEventListener('mousemove', (e) => {
            if (this.isDragging) {
                this.drag(e);
            }
        });

        document.addEventListener('mouseup', () => {
            if (this.isDragging) {
                this.stopDrag();
            }
        });

        // Touch events for mobile
        floatingTimer.addEventListener('touchstart', (e) => {
            if (e.target === floatingTimer || e.target.classList.contains('timer-label')) {
                e.preventDefault();
                this.startDrag(e.touches[0]);
            }
        });

        document.addEventListener('touchmove', (e) => {
            if (this.isDragging) {
                e.preventDefault();
                this.drag(e.touches[0]);
            }
        });

        document.addEventListener('touchend', () => {
            if (this.isDragging) {
                this.stopDrag();
            }
        });
    }

    startDrag(e) {
        this.isDragging = true;
        const floatingTimer = document.getElementById('floatingTimer');
        const rect = floatingTimer.getBoundingClientRect();
        
        this.dragOffset.x = e.clientX - rect.left;
        this.dragOffset.y = e.clientY - rect.top;
        
        floatingTimer.classList.add('dragging');
        document.body.style.userSelect = 'none';
    }

    drag(e) {
        const floatingTimer = document.getElementById('floatingTimer');
        const x = e.clientX - this.dragOffset.x;
        const y = e.clientY - this.dragOffset.y;
        
        // Keep timer within viewport bounds
        const maxX = window.innerWidth - floatingTimer.offsetWidth;
        const maxY = window.innerHeight - floatingTimer.offsetHeight;
        
        const clampedX = Math.max(0, Math.min(x, maxX));
        const clampedY = Math.max(0, Math.min(y, maxY));
        
        floatingTimer.style.left = clampedX + 'px';
        floatingTimer.style.top = clampedY + 'px';
        floatingTimer.style.right = 'auto';
        floatingTimer.style.bottom = 'auto';
    }

    stopDrag() {
        this.isDragging = false;
        const floatingTimer = document.getElementById('floatingTimer');
        floatingTimer.classList.remove('dragging');
        document.body.style.userSelect = '';
    }
    
    async addItem(event) {
        const btn = event.target;
        const category = btn.dataset.category;
        const input = document.querySelector(`.item-input[data-category="${category}"]`);
        const content = input.value.trim();
        
        if (!content) {
            this.showMessage('Please enter some feedback', 'error');
            return;
        }
        
        // Disable button and show loading
        btn.disabled = true;
        btn.textContent = 'Adding...';
        
        try {
            const response = await fetch(`/retrospectives/${this.retrospectiveId}/add-item-ajax`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                credentials: 'include',
                body: JSON.stringify({
                    content: content,
                    category: category
                })
            });
            
            
        if (response.ok) {
            const data = await response.json();
            // Don't add the card here - let WebSocket handle it to avoid duplicates
            input.value = '';
            this.showMessage('Feedback added successfully!', 'success');
        } else {
                const responseText = await response.text();
                console.error('Add item error response:', responseText);
                try {
                    const error = JSON.parse(responseText);
                    this.showMessage(error.message || 'Failed to add feedback', 'error');
                } catch (e) {
                    console.error('Response was not JSON:', responseText.substring(0, 200));
                    this.showMessage('Server error occurred', 'error');
                }
            }
        } catch (error) {
            console.error('Error adding item:', error);
            this.showMessage('Failed to add feedback', 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = 'Add';
        }
    }
    
    addPostItCard(category, item) {
        const container = document.getElementById(`${category}Items`);
        if (!container) return;
        
        const postIt = document.createElement('div');
        postIt.className = `post-it ${category}`;
        postIt.dataset.itemId = item.id;
        
        const now = new Date();
        const timeString = now.toLocaleTimeString('en-US', { 
            hour: '2-digit', 
            minute: '2-digit',
            hour12: false 
        });
        
        postIt.innerHTML = `
            <div class="post-it-content">${this.escapeHtml(item.content)}</div>
            <div class="post-it-delete">×</div>
        `;
        
        container.appendChild(postIt);
        
        // Add event listeners for edit and delete
        this.addPostItEventListeners(postIt, item.id, category);
        
        // Scroll to bottom
        container.scrollTop = container.scrollHeight;
    }
    
    updateItemCount(category) {
        const header = document.querySelector(`.column-header[data-category="${category}"]`);
        if (header) {
            const countElement = header.querySelector('.item-count');
            if (countElement) {
                const currentCount = parseInt(countElement.textContent) || 0;
                countElement.textContent = currentCount + 1;
            }
        }
    }

    addPostItEventListeners(postIt, itemId, category) {
        const content = postIt.querySelector('.post-it-content');
        const deleteBtn = postIt.querySelector('.post-it-delete');
        
        // Edit on click
        if (content) {
            content.addEventListener('click', () => {
                this.editPostIt(postIt, itemId, category);
            });
        }
        
        // Delete on click
        if (deleteBtn) {
            deleteBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.deletePostIt(itemId, category);
            });
        }
    }

    editPostIt(postIt, itemId, category) {
        const content = postIt.querySelector('.post-it-content');
        const currentText = content.textContent;
        
        // Remove the click event listener to prevent re-triggering
        const newContent = content.cloneNode(true);
        content.parentNode.replaceChild(newContent, content);
        
        // Create textarea and OK button
        const input = document.createElement('textarea');
        input.value = currentText;
        input.className = 'post-it-edit-input';
        input.rows = 3;
        
        const okBtn = document.createElement('button');
        okBtn.textContent = 'OK';
        okBtn.className = 'post-it-ok-btn';
        
        // Replace content with input and button
        newContent.innerHTML = '';
        newContent.appendChild(input);
        newContent.appendChild(okBtn);
        
        // Focus input
        input.focus();
        input.select();
        
        // OK button click
        okBtn.addEventListener('click', (e) => {
            e.stopPropagation(); // Prevent event bubbling
            const newText = input.value.trim();
            if (newText && newText !== currentText) {
                this.updatePostIt(itemId, newText, category);
            } else {
                // Cancel edit - restore original text and re-attach event listener
                newContent.textContent = currentText;
                this.addPostItEventListeners(postIt, itemId, category);
            }
        });
        
        // Enter key
        input.addEventListener('keypress', (e) => {
            e.stopPropagation(); // Prevent event bubbling
            if (e.key === 'Enter') {
                okBtn.click();
            }
        });
        
        // Escape key
        input.addEventListener('keydown', (e) => {
            e.stopPropagation(); // Prevent event bubbling
            if (e.key === 'Escape') {
                newContent.textContent = currentText;
                this.addPostItEventListeners(postIt, itemId, category);
            }
        });
        
        // Prevent click events on input from bubbling
        input.addEventListener('click', (e) => {
            e.stopPropagation();
        });
    }

    async updatePostIt(itemId, newText, category) {
        try {
            const response = await fetch(`/retrospectives/${this.retrospectiveId}/update-item-ajax`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                credentials: 'include',
                body: JSON.stringify({
                    itemId: itemId,
                    content: newText
                })
            });
            
            if (response.ok) {
                const data = await response.json();
                // Update the post-it content and re-attach event listeners
                const postIt = document.querySelector(`.post-it[data-item-id="${itemId}"]`);
                if (postIt) {
                    const content = postIt.querySelector('.post-it-content');
                    content.textContent = newText;
                    // Re-attach event listeners after successful update
                    this.addPostItEventListeners(postIt, itemId, category);
                }
                this.showMessage('Post updated successfully!', 'success');
            } else {
                this.showMessage('Failed to update post', 'error');
            }
        } catch (error) {
            console.error('Error updating post:', error);
            this.showMessage('Failed to update post', 'error');
        }
    }

    showConfirmModal(title, message) {
        return new Promise((resolve) => {
            const modal = document.getElementById('confirmModal');
            const titleEl = document.getElementById('confirmModalTitle');
            const bodyEl = document.getElementById('confirmModalBody');
            const cancelBtn = document.getElementById('confirmModalCancel');
            const confirmBtn = document.getElementById('confirmModalConfirm');
            
            titleEl.textContent = title;
            bodyEl.textContent = message;
            
            modal.classList.add('active');
            
            const handleConfirm = () => {
                modal.classList.remove('active');
                confirmBtn.removeEventListener('click', handleConfirm);
                cancelBtn.removeEventListener('click', handleCancel);
                modal.removeEventListener('click', handleBackdropClick);
                resolve(true);
            };
            
            const handleCancel = () => {
                modal.classList.remove('active');
                confirmBtn.removeEventListener('click', handleConfirm);
                cancelBtn.removeEventListener('click', handleCancel);
                modal.removeEventListener('click', handleBackdropClick);
                resolve(false);
            };
            
            const handleBackdropClick = (e) => {
                if (e.target === modal) {
                    handleCancel();
                }
            };
            
            confirmBtn.addEventListener('click', handleConfirm);
            cancelBtn.addEventListener('click', handleCancel);
            modal.addEventListener('click', handleBackdropClick);
        });
    }
    
    async deletePostIt(itemId, category) {
        const confirmed = await this.showConfirmModal(
            'Delete Post',
            'Are you sure you want to delete this post? This action cannot be undone.'
        );
        
        if (!confirmed) {
            return;
        }
        
        try {
            const response = await fetch(`/retrospectives/${this.retrospectiveId}/delete-item-ajax`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                credentials: 'include',
                body: JSON.stringify({
                    itemId: itemId
                })
            });
            
            const responseData = await response.json();
            
            if (response.ok) {
                // Remove the post-it from DOM
                const postIt = document.querySelector(`.post-it[data-item-id="${itemId}"]`);
                if (postIt) {
                    postIt.remove();
                }
                this.updateItemCount(category);
                this.showMessage('Post deleted successfully!', 'success');
            } else {
                this.showMessage(responseData.message || 'Failed to delete post', 'error');
            }
        } catch (error) {
            console.error('Error deleting post:', error);
            this.showMessage('Failed to delete post', 'error');
        }
    }

    addEventListenersToExistingPosts() {
        // Add event listeners to existing post-its
        const postIts = document.querySelectorAll('.post-it');
        
        postIts.forEach(postIt => {
            const itemId = postIt.dataset.itemId;
            const feedbackColumn = postIt.closest('.feedback-column');
            const category = feedbackColumn?.dataset?.category;
            
            if (!itemId || !category) {
                return;
            }
            
            this.addPostItEventListeners(postIt, itemId, category);
        });
    }
    
    async nextStep() {
        const nextStepBtn = document.getElementById('nextStepBtn');
        if (nextStepBtn) {
            nextStepBtn.disabled = true;
            nextStepBtn.textContent = 'Processing...';
        }
        
        try {
            // Stop timer if it's running before moving to next step
            if (this.timerInterval && !this.timerManuallyStopped) {
                console.log('Stopping timer before moving to next step');
                await this.stopTimer();
            }
            
            const response = await fetch(`/retrospectives/${this.retrospectiveId}/next-step`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin'
            });
            
            if (response.ok) {
                const data = await response.json();
                this.showMessage('Moved to next step!', 'success');
                
                // If moving to review step, initialize review phase
                if (data.nextStep === 'review') {
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    // Reload page to show new step
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                }
            } else {
                const error = await response.json();
                this.showMessage(error.message || 'Failed to move to next step', 'error');
            }
        } catch (error) {
            console.error('Error moving to next step:', error);
            this.showMessage('Failed to move to next step', 'error');
        } finally {
            if (nextStepBtn) {
                nextStepBtn.disabled = false;
                nextStepBtn.textContent = 'Next Step';
            }
        }
    }
    
    async completeRetrospective() {
        const completeBtn = document.getElementById('completeRetrospectiveBtn');
        
        // Show confirmation
        const confirmed = await this.showConfirmModal(
            'Complete Retrospective',
            'Are you sure you want to complete this retrospective? This action cannot be undone.'
        );
        
        if (!confirmed) {
            return;
        }
        
        if (completeBtn) {
            completeBtn.disabled = true;
            completeBtn.textContent = 'Completing...';
        }
        
        try {
            const response = await fetch(`/retrospectives/${this.retrospectiveId}/complete`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin'
            });
            
            if (response.ok) {
                const data = await response.json();
                this.showMessage('Retrospective completed successfully!', 'success');
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
            } else {
                const error = await response.json();
                this.showMessage(error.message || 'Failed to complete retrospective', 'error');
            }
        } catch (error) {
            console.error('Error completing retrospective:', error);
            this.showMessage('Failed to complete retrospective', 'error');
        } finally {
            if (completeBtn) {
                completeBtn.disabled = false;
                completeBtn.textContent = 'Complete Retrospective';
            }
        }
    }
    
    async checkInitialTimerStatus() {
        try {
            const response = await fetch(`/retrospectives/${this.retrospectiveId}/timer-status`, {
                credentials: 'same-origin'
            });
            if (response.ok) {
                const data = await response.json();
                this.restoreTimerFromServer(data);
            }
        } catch (error) {
            console.error('Error checking initial timer status:', error);
        }
        
        // Timer status is now checked in startPolling() to avoid conflicts
    }
    
    restoreTimerFromServer(data) {
        if (data.isActive && data.remainingSeconds > 0) {
            this.timerManuallyStopped = false;
            this.startTimerDisplayFromServer(data.remainingSeconds);
            this.showAddItemForms();
            
            // If in voting phase, activate voting
            if (this.isInDiscussionStep()) {
                console.log('Restoring voting in voting phase after page refresh');
                if (typeof this.initVoting === 'function') {
                    this.initVoting();
                } else {
                    console.error('initVoting function not found!');
                }
            }
        } else {
            this.timerManuallyStopped = true;
            // Show "Timer stopped" message for members
            this.stopTimerDisplay();
        }
    }
    
    updateTimerFromServer(data) {
        
        // Don't show timer if it was manually stopped
        if (this.timerManuallyStopped) {
            return;
        }
        
        if (data.isActive && data.remainingSeconds > 0) {
            // Always resync timer with server time
            this.timerEndTime = Date.now() + (data.remainingSeconds * 1000);
            
            // Show floating timer and timer display if not already shown (only if not in action phase)
            const floatingTimer = document.getElementById('floatingTimer');
            const timerDisplay = document.getElementById('timerDisplay');
            if (floatingTimer && this.shouldShowTimer()) {
                floatingTimer.style.display = 'block';
            }
            if (timerDisplay) {
                timerDisplay.style.display = 'flex';
            }
            
            // Start timer interval if not already running
            if (!this.timerInterval) {
                this.timerInterval = setInterval(() => {
                    this.updateTimerDisplay();
                }, 1000);
                this.showAddItemForms();
            }
        } else {
            // Timer expired or stopped - keep showing 0:00
            if (this.timerInterval) {
                clearInterval(this.timerInterval);
                this.timerInterval = null;
            }
            
            const floatingTimer = document.getElementById('floatingTimer');
            const timerDisplay = document.getElementById('timerDisplay');
            const timerTime = document.getElementById('timerTime');
            
            if (floatingTimer && this.shouldShowTimer()) {
                floatingTimer.style.display = 'block';
            }
            if (timerDisplay && timerTime) {
                timerDisplay.style.display = 'flex';
                timerTime.textContent = '0:00';
                timerDisplay.className = 'timer-display danger';
            }
        }
    }
    
    showMessage(message, type) {
        // Remove existing messages
        document.querySelectorAll('.retrospective-message').forEach(msg => msg.remove());
        
        const messageEl = document.createElement('div');
        messageEl.className = `retrospective-message ${type}`;
        messageEl.textContent = message;
        
        document.body.appendChild(messageEl);
        
        // Auto-remove after 3 seconds
        setTimeout(() => {
            if (messageEl.parentNode) {
                messageEl.remove();
            }
        }, 3000);
    }
    
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    connectToMercure() {
        this.showConnectionStatus('connecting');
        
        // Connect to Mercure WebSocket
        this.connectToWebSocket();
    }
    
    async connectToWebSocket() {
        try {
            // Get Mercure JWT token
            const response = await fetch(`/retrospectives/${this.retrospectiveId}/mercure-token`, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                credentials: 'include'
            });
            
            if (!response.ok) {
                throw new Error('Failed to get Mercure token');
            }
            
            const data = await response.json();
            const mercureToken = data.token;
            
            // Create EventSource connection to Mercure with JWT token
            const url = new URL('/.well-known/mercure', window.location.origin);
            url.searchParams.append('topic', `retrospective/${this.retrospectiveId}`);
            url.searchParams.append('topic', `retrospective/${this.retrospectiveId}/timer`);
            url.searchParams.append('topic', `retrospective/${this.retrospectiveId}/review`);
            url.searchParams.append('topic', `retrospective/${this.retrospectiveId}/connected-users`);
            url.searchParams.append('topic', `retrospective/${this.retrospectiveId}/step`);
            url.searchParams.append('topic', `retrospectives/${this.retrospectiveId}/discussion`);
            
            // Mercure accepts JWT token as query parameter for EventSource
            // Since EventSource doesn't support custom headers, we pass the token as query parameter
            url.searchParams.append('authorization', mercureToken);
            
            this.eventSource = new EventSource(url.toString());
            
            this.eventSource.onopen = () => {
                console.log('WebSocket connected');
                this.showConnectionStatus('connected');
            };
            
            this.eventSource.onmessage = (event) => {
                try {
                    const data = JSON.parse(event.data);
                    this.handleWebSocketMessage(data);
                } catch (error) {
                    console.error('Error parsing WebSocket message:', error);
                }
            };
            
            this.eventSource.onerror = (error) => {
                console.error('WebSocket error:', error);
                this.showConnectionStatus('error');
                
                // Close the failed connection
                if (this.eventSource) {
                    this.eventSource.close();
                    this.eventSource = null;
                }
                
                // Clear any existing reconnect timeout
                if (this.reconnectTimeout) {
                    clearTimeout(this.reconnectTimeout);
                }
                
                // Reconnect after 5 seconds if not already reconnecting
                if (!this.isReconnecting) {
                    this.isReconnecting = true;
                    this.reconnectTimeout = setTimeout(() => {
                        this.isReconnecting = false;
                        this.connectToMercure();
                    }, 5000);
                }
            };
            
        } catch (error) {
            console.error('Error connecting to WebSocket:', error);
            this.showConnectionStatus('error');
        }
    }
    
    disconnectFromWebSocket() {
        if (this.eventSource) {
            this.eventSource.close();
            this.eventSource = null;
        }
    }
    
    handleWebSocketMessage(data) {
        console.log('WebSocket message received:', data);
        
        switch (data.type) {
            case 'timer_started':
                this.handleTimerStarted(data);
                break;
            case 'timer_stopped':
                this.handleTimerStopped(data);
                break;
            case 'timer_updated':
                this.handleTimerUpdated(data);
                break;
            case 'item_added':
                this.handleItemAdded(data);
                break;
            case 'item_updated':
                this.handleItemUpdated(data);
                break;
            case 'item_deleted':
                this.handleItemDeleted(data);
                break;
            case 'group_created':
                this.handleGroupCreated(data);
                break;
            case 'group_updated':
                this.handleGroupUpdated(data);
                break;
            case 'item_added_to_group':
                this.handleItemAddedToGroup(data);
                break;
            case 'item_separated':
                this.handleItemSeparated(data);
                break;
            case 'items_reordered':
                this.handleItemsReordered(data);
                break;
            case 'connected_users_updated':
                if (data.timerLikeStates) {
                }
                this.handleConnectedUsersUpdated(data);
                // Also handle timer like states if present
                if (data.timerLikeStates) {
                    this.handleTimerLikeStatesFromHeartbeat(data.timerLikeStates);
                }
                break;
            case 'user_joined':
                this.handleUserJoined(data);
                break;
            case 'user_left':
                this.handleUserLeft(data);
                break;
            case 'vote_updated':
                this.handleVoteUpdated(data);
                break;
            case 'step_changed':
                this.handleStepChanged(data);
                break;
            case 'item_discussed':
                this.handleItemDiscussed(data);
                break;
            case 'timer_like_update':
                this.handleTimerLikeUpdate(data);
                break;
            default:
                console.log('Unknown WebSocket message type:', data.type);
        }
    }
    
    handleVoteUpdated(data) {
        console.log('Vote updated via WebSocket:', data);
        // Update the vote display for other users
        // For now, just log it - full sync can be implemented later
    }
    
    handleStepChanged(data) {
        console.log('Step changed via WebSocket:', data);
        this.showMessage(data.message || 'Moving to next step...', 'info');
        
        // Reload page after a short delay to show the new step (including completed)
        setTimeout(() => {
            window.location.reload();
        }, 1500);
    }
    
    handleItemDiscussed(data) {
        console.log('Item discussed via WebSocket:', data);
        
        const { itemId, itemType } = data;
        
        // Find the card element based on item type and ID
        let cardElement = null;
        
        if (itemType === 'item') {
            cardElement = document.querySelector(`[data-item-id="${itemId}"]`);
        } else if (itemType === 'group') {
            cardElement = document.querySelector(`[data-group-id="${itemId}"]`);
        }
        
        if (cardElement) {
            // Apply the same visual changes as if the current user marked it as discussed
            // Skip saving to backend since this update came via WebSocket
            this.markAsDiscussed(cardElement, itemId, itemType, true);
            
            // Show a subtle notification that another user marked this as discussed
            const memberName = data.memberName || 'Another user';
            this.showMessage(`${memberName} marked an item as discussed`, 'info', 2000);
        }
    }

    handleTimerLikeUpdate(data) {
        console.log('Timer like update via WebSocket:', data);
        
        const { userId, userName, isLiked } = data;
        const currentUserId = this.user?.id || window.user?.id;
        
        // Don't process our own like updates (we already handled them locally)
        if (userId === currentUserId) {
            return;
        }
        
        // Find the user element in sidebar by ID or name
        let userElement = null;
        
        // Try different selectors to find the user in sidebar
        const possibleSelectors = [
            `[data-user-id="${userId}"]`,
            `.user-${userId}`,
            `.user-item[data-user="${userId}"]`,
            `.sidebar-user[data-id="${userId}"]`,
            `.connected-user[data-user-id="${userId}"]`
        ];
        
        for (const selector of possibleSelectors) {
            userElement = document.querySelector(selector);
            if (userElement) break;
        }
        
        // If still not found, try to find by text content (username)
        if (!userElement && userName) {
            const allUserElements = document.querySelectorAll('.user-item, .sidebar-user, .connected-user, [class*="user"]');
            for (const el of allUserElements) {
                if (el.textContent.includes(userName)) {
                    userElement = el;
                    break;
                }
            }
        }
        
        if (userElement) {
            if (isLiked) {
                userElement.classList.add('timer-liked');
                console.log(`User ${userName} liked the timer`);
            } else {
                userElement.classList.remove('timer-liked');
                console.log(`User ${userName} unliked the timer`);
            }
        } else {
            console.log(`User element not found for timer like update. User ID: ${userId}, User Name: ${userName}`);
        }
    }

    // Timer like state is automatically saved via broadcastTimerLike() endpoint

    // Timer like state restoration is now handled entirely by WebSocket heartbeat
    // No manual restoration needed - this eliminates race conditions

    updateUserSidebarBackgroundForUser(userId, userName, isLiked) {
        
        // Find the user element in sidebar by ID or name
        let userElement = null;
        
        // Try different selectors to find the user in sidebar
        const possibleSelectors = [
            `[data-user-id="${userId}"]`,
            `.user-${userId}`,
            `.user-item[data-user="${userId}"]`,
            `.sidebar-user[data-id="${userId}"]`,
            `.connected-user[data-user-id="${userId}"]`
        ];
        
        
        for (const selector of possibleSelectors) {
            userElement = document.querySelector(selector);
            if (userElement) break;
        }
        
        // If still not found, try to find by text content (username)
        if (!userElement && userName) {
            const allUserElements = document.querySelectorAll('.user-item, .sidebar-user, .connected-user, [class*="user"]');
            
            for (const el of allUserElements) {
                if (el.textContent.includes(userName)) {
                    userElement = el;
                    break;
                }
            }
        }
        
        if (userElement) {
            
            if (isLiked) {
                userElement.classList.add('timer-liked');
            } else {
                userElement.classList.remove('timer-liked');
            }
            
        } else {
        }
    }

    handleTimerLikeStatesFromHeartbeat(timerLikeStates) {
        // Store the states for potential reapplication after DOM updates
        this.lastTimerLikeStates = timerLikeStates;
        
        // Use the connected users manager to handle timer like states
        if (this.connectedUsersManager) {
            this.connectedUsersManager.handleTimerLikeStates(timerLikeStates);
        }
    }

    clearTimerLikeState() {
        try {
            const currentUserId = this.user?.id || window.user?.id;
            const retrospectiveId = this.retrospectiveId || window.retrospectiveId;
            
            if (!currentUserId || !retrospectiveId) {
                return;
            }
            
            // Timer like state is managed on the server side
            // No need to clear localStorage since we're not using it anymore
            console.log(`Timer like state is managed on server for user ${currentUserId} in retrospective ${retrospectiveId}`);
        } catch (error) {
            console.error('Error clearing timer like state:', error);
        }
    }
    
    
    async checkConnectedUsers() {
        try {
            const response = await fetch(`/retrospectives/${this.retrospectiveId}/connected-users`, {
                credentials: 'include',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            
            if (response.ok) {
                const data = await response.json();
                try {
                    this.updateConnectedUsers(data.users);
                } catch (error) {
                    console.error('Error in updateConnectedUsers:', error);
                }
            } else {
                console.error('Failed to get connected users:', response.status, response.statusText);
            }
        } catch (error) {
            console.error('Error checking connected users:', error);
        }
    }
    
    updateConnectedUsers(users) {
        if (this.connectedUsersManager) {
            this.connectedUsersManager.updateUsers(users);
        }
    }
    
    async joinRetrospective() {
        try {
            
            // Test if the method is being called
            
            const response = await fetch(`/retrospectives/${this.retrospectiveId}/join`, {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            
            if (response.ok) {
                const data = await response.json();
            } else {
                const errorText = await response.text();
                console.error('Failed to join retrospective:', response.status, response.statusText, errorText);
            }
        } catch (error) {
            console.error('Error joining retrospective:', error);
        }
    }
    
    async leaveRetrospective() {
        try {
            const response = await fetch(`/retrospectives/${this.retrospectiveId}/leave`, {
                method: 'POST',
                credentials: 'same-origin'
            });
            
            if (response.ok) {
            }
        } catch (error) {
            console.error('Error leaving retrospective:', error);
        }
    }
    
    
    handleTimerStarted(data) {
        if (data.remainingSeconds > 0) {
            this.timerManuallyStopped = false;
            this.timerExpiredMessageShown = false;
            this.startTimerDisplayFromServer(data.remainingSeconds);
            this.showAddItemForms();
            
            // Start voting if we're in voting phase
            const inDiscussion = this.isInDiscussionStep();
            console.log('Timer started - in voting phase?', inDiscussion);
            if (inDiscussion) {
                console.log('Starting voting in voting phase');
                if (typeof this.initVoting === 'function') {
                    this.initVoting();
                } else {
                    console.error('initVoting function not found!');
                }
            }
        }
    }
    
    handleTimerStopped(data) {
        this.timerManuallyStopped = true;
        // Don't call stopTimer() - it would send another request to backend creating a loop!
        // Just stop the local timer display
        this.stopTimerDisplay();
        this.hideAddItemForms();
        
        // Clear all timer like states when timer is stopped
        if (this.connectedUsersManager) {
            this.connectedUsersManager.clearAllTimerLikeStates();
        }
        
        // Stop voting if we're in voting phase
        if (this.isInDiscussionStep() && this.votingActive) {
            console.log('Stopping voting in voting phase');
            this.stopVoting();
        }
    }
    
    handleTimerUpdated(data) {
        if (data.remainingSeconds > 0 && !this.timerManuallyStopped) {
            this.timerEndTime = Date.now() + (data.remainingSeconds * 1000);
        }
    }
    
    handleItemAdded(data) {
        // Check if item already exists in DOM to avoid duplicates
        const existingItem = document.querySelector(`.post-it[data-item-id="${data.item?.id}"]`);
        if (!existingItem && data.item) {
            // Only show item to its author in feedback phase
            if (this.isInFeedbackStep()) {
                const currentUsername = this.user?.firstName || 'Unknown';
                const itemAuthorFirstName = data.item.author?.firstName || '';
                
                if (currentUsername === itemAuthorFirstName) {
                    this.addPostItCard(data.item.category, data.item);
                    this.updateItemCount(data.item.category);
                }
            } else {
                // In review and other phases, show all items
                this.addPostItCard(data.item.category, data.item);
                this.updateItemCount(data.item.category);
            }
        }
    }
    
    handleItemUpdated(data) {
        if (this.isInFeedbackStep()) {
            this.loadFeedbackData();
        }
    }
    
    handleItemDeleted(data) {
        if (this.isInFeedbackStep()) {
            this.loadFeedbackData();
        }
    }
    
    handleGroupCreated(data) {
        console.log('Group created via WebSocket, reloading data');
        if (this.isInReviewStep()) {
            this.loadReviewData();
        }
    }
    
    handleGroupUpdated(data) {
        console.log('Group updated via WebSocket, reloading data');
        if (this.isInReviewStep()) {
            this.loadReviewData();
        }
    }
    
    handleItemAddedToGroup(data) {
        if (this.isInReviewStep()) {
            this.loadReviewData();
        }
    }
    
    handleItemSeparated(data) {
        if (this.isInReviewStep()) {
            this.loadReviewData();
        }
    }
    
    handleItemsReordered(data) {
        if (this.isInReviewStep()) {
            // Check if this reorder was triggered by our own action
            const category = data.category;
            
            // Update initial order after successful reorder from server
            if (this.initialOrder && this.initialOrder[category]) {
                this.initialOrder[category] = {
                    itemIds: data.item_ids || [],
                    groupIds: data.group_ids || []
                };
                console.log('Updated initial order for', category, ':', this.initialOrder[category]);
            }
            
            // Always reload data for this category to sync with server
            // This ensures all connected users see the reorder, regardless of who initiated it
            this.loadReviewData();
        }
    }
    
    handleConnectedUsersUpdated(data) {
        // Process timer like states FIRST, before updating connected users
        if (data.timerLikeStates) {
            this.handleTimerLikeStatesFromHeartbeat(data.timerLikeStates);
        }
        
        // Then update connected users
        this.updateConnectedUsers(data.users);
    }
    
    handleUserJoined(data) {
        this.updateConnectedUsers(data.users);
    }
    
    handleUserLeft(data) {
        this.updateConnectedUsers(data.users);
    }

    
    handleStepChanged(data) {
        this.showMessage(data.message, 'success');
        
        // Reload page to show new step (including completed)
        setTimeout(() => {
            window.location.reload();
        }, 1500);
    }
    
    showConnectionStatus(status) {
        // Remove existing status indicator
        const existingStatus = document.querySelector('.connection-status');
        if (existingStatus) {
            existingStatus.remove();
        }
        
        if (status === 'disconnected') {
            const statusEl = document.createElement('div');
            statusEl.className = 'connection-status disconnected';
            statusEl.innerHTML = '🔴 Disconnected - Attempting to reconnect...';
            statusEl.style.cssText = `
                position: fixed;
                top: 10px;
                right: 10px;
                background: #dc3545;
                color: white;
                padding: 8px 12px;
                border-radius: 4px;
                font-size: 12px;
                z-index: 1000;
                animation: pulse 1s infinite;
            `;
            document.body.appendChild(statusEl);
        } else if (status === 'connected') {
            const statusEl = document.createElement('div');
            statusEl.className = 'connection-status connected';
            statusEl.innerHTML = '🟢 Connected';
            statusEl.style.cssText = `
                position: fixed;
                top: 10px;
                right: 10px;
                background: #28a745;
                color: white;
                padding: 8px 12px;
                border-radius: 4px;
                font-size: 12px;
                z-index: 1000;
            `;
            document.body.appendChild(statusEl);
            
            // Remove after 3 seconds
            setTimeout(() => {
                if (statusEl.parentNode) {
                    statusEl.remove();
                }
            }, 3000);
        } else if (status === 'polling') {
            const statusEl = document.createElement('div');
            statusEl.className = 'connection-status polling';
            statusEl.innerHTML = '🟡 Polling';
            statusEl.style.cssText = `
                position: fixed;
                top: 10px;
                right: 10px;
                background: #ffc107;
                color: black;
                padding: 8px 12px;
                border-radius: 4px;
                font-size: 12px;
                z-index: 1000;
            `;
            document.body.appendChild(statusEl);
        }
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    
    if (typeof window.retrospectiveId !== 'undefined') {
        try {
            new RetrospectiveBoard();
        } catch (error) {
            console.error('Error initializing RetrospectiveBoard:', error);
        }
    } else {
    }
});

// Also try to initialize immediately if DOM is already loaded
if (document.readyState === 'loading') {
} else {
    if (typeof window.retrospectiveId !== 'undefined') {
        try {
            new RetrospectiveBoard();
        } catch (error) {
            console.error('Error initializing RetrospectiveBoard immediately:', error);
        }
    }
}

// Review Phase Methods
RetrospectiveBoard.prototype.isInReviewStep = function() {
    const isReview = document.querySelector('.review-phase') !== null;
    return isReview;
};

RetrospectiveBoard.prototype.isInDiscussionStep = function() {
    const isDiscussion = document.querySelector('.voting-phase') !== null;
    return isDiscussion;
};

RetrospectiveBoard.prototype.isInActionStep = function() {
    const isAction = document.querySelector('.actions-phase') !== null;
    return isAction;
};

RetrospectiveBoard.prototype.isInFeedbackStep = function() {
    const isFeedback = document.querySelector('.feedback-columns') !== null;
    return isFeedback;
};

RetrospectiveBoard.prototype.shouldShowTimer = function() {
    // Don't show timer in review phase, action phase, or completed retrospectives
    const isCompleted = document.querySelector('.completed-retrospective') !== null;
    return !this.isInReviewStep() && !this.isInActionStep() && !isCompleted;
};

RetrospectiveBoard.prototype.initReviewPhase = function() {
    this.loadReviewData();
    // Don't call initReviewDragAndDrop here - it will be called after rendering
    // Don't store initial order here - it will be stored on first drag
};

RetrospectiveBoard.prototype.loadReviewData = async function() {
    try {
        const response = await fetch(`/retrospectives/${this.retrospectiveId}/review-data`, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            credentials: 'same-origin'
        });

        if (response.ok) {
            const data = await response.json();
            this.reviewItems = data.items || [];
            this.reviewGroups = data.groups || [];
            this.renderReviewBoard();
        } else {
            console.error('Failed to load review data:', response.status);
        }
    } catch (error) {
        console.error('Error loading review data:', error);
    }
};

RetrospectiveBoard.prototype.renderReviewBoard = function() {
    // Clear all columns
    const categories = ['wrong', 'good', 'improved', 'random'];
    categories.forEach(category => {
        const container = document.getElementById(`${category}Items`);
        if (container) {
            container.innerHTML = '';
        }
    });

    // Group items by category
    const itemsByCategory = {
        wrong: [],
        good: [],
        improved: [],
        random: []
    };

    this.reviewItems.forEach(item => {
        if (itemsByCategory[item.category]) {
            itemsByCategory[item.category].push(item);
        }
    });

    // Render items and groups in their respective columns
    categories.forEach(category => {
        const container = document.getElementById(`${category}Items`);
        const countElement = document.getElementById(`${category}Count`);
        
        if (container && countElement) {
            const items = itemsByCategory[category];
            
            // Find groups that should be displayed in this column
            // A group is displayed in a column based on its display_category
            // If display_category is null (old groups), use the category of the first item
            const groupsInThisColumn = this.reviewGroups.filter(group => {
                if (group.display_category) {
                    return group.display_category === category;
                } else {
                    // For old groups without display_category, check if any item belongs to this category
                    return group.items && group.items.some(item => item.category === category);
                }
            });
            
            countElement.textContent = items.length;

            // Collect all grouped item IDs from all groups, not just this column
            const groupedItemIds = new Set();
            this.reviewGroups.forEach(group => {
                if (group.items) {
                    group.items.forEach(item => {
                        groupedItemIds.add(item.id);
                    });
                }
            });

            // Sort groups and items by position
            groupsInThisColumn.sort((a, b) => (a.position || 0) - (b.position || 0));
            items.sort((a, b) => (a.position || 0) - (b.position || 0));

            // Create a combined array of all elements with their positions
            const allElements = [];
            
            // Add groups
            groupsInThisColumn.forEach(group => {
                // For old groups, filter items by category
                const groupItems = group.display_category 
                    ? group.items 
                    : group.items.filter(item => item.category === category);
                
                if (groupItems.length > 0) {
                    const element = this.createCombinedGroupElement(groupItems, category);
                    element.id = `group-${group.id}`; // Set element ID
                    element.dataset.groupId = group.id; // Set group ID directly
                    allElements.push({
                        type: 'group',
                        position: group.position || 0,
                        data: group,
                        element: element
                    });
                }
            });

            // Add individual items (not in groups)
            items.forEach(item => {
                if (!groupedItemIds.has(item.id)) {
                    allElements.push({
                        type: 'item',
                        position: item.position || 0,
                        data: item,
                        element: this.createReviewItemElement(item)
                    });
                }
            });

            // Sort all elements by position
            allElements.sort((a, b) => a.position - b.position);

            // Render elements in order
            allElements.forEach(elementData => {
                container.appendChild(elementData.element);
            });
        }
    });
    
        // Re-initialize drag and drop after rendering
        this.initReviewDragAndDrop();
};

RetrospectiveBoard.prototype.findItemsInGroup = function(groupElement) {
    // Find all items that belong to this group
    const itemIds = [];
    
    // Look for the group in reviewGroups data
    const groupId = groupElement.dataset.groupId;
    if (groupId) {
        const group = this.reviewGroups.find(g => g.id == groupId);
        if (group && group.items) {
            return group.items.map(item => item.id);
        }
    }
    
    // Fallback: try to extract from the content
    const content = groupElement.querySelector('.review-item-content');
    if (content) {
        // This is a simplified approach - in practice, we should use the group data
        // For now, return empty array to avoid errors
        return [];
    }
    
    return itemIds;
};

RetrospectiveBoard.prototype.getDragAfterElement = function(container, y) {
    const draggableElements = [...container.querySelectorAll('.review-item:not(.dragging)')];
    
    return draggableElements.reduce((closest, child) => {
        const box = child.getBoundingClientRect();
        // Check if cursor is in the gap between items (not over an item)
        // This gives priority to grouping (drop on item) over reordering (drop in gap)
        const centerY = box.top + box.height / 2;
        const offset = y - centerY;
        
        // Only consider this position if we're past the center of the item
        if (offset < 0 && offset > closest.offset) {
            return { offset: offset, element: child };
        } else {
            return closest;
        }
    }, { offset: Number.NEGATIVE_INFINITY }).element;
};

RetrospectiveBoard.prototype.showAllDropZones = function(container, draggedElement) {
    console.log('=== SHOW ALL DROP ZONES ===');
    console.log('Container:', container.id);
    console.log('Dragged element:', draggedElement?.id);
    
    // Remove any existing placeholders first
    this.hideAllDropZones();
    
    // Get all items AND groups in the container (including the dragged one for position calculation)
    const allItems = [...container.querySelectorAll('.review-item, .combined-group')];
    const draggedIndex = allItems.indexOf(draggedElement);
    console.log('Dragged element index:', draggedIndex);
    console.log('Total items/groups in container:', allItems.length);
    
    // Get all items excluding the dragged one
    const items = allItems.filter(item => item !== draggedElement);
    console.log('Items found (excluding dragged element):', items.length);
    
    // Create placeholder before first item (only if dragged is not at position 0)
    if (draggedIndex !== 0) {
        console.log('Creating placeholder before first item');
        this.createDropZone(container, null);
    }
    
    // Create placeholder after each item
    items.forEach((item, index) => {
        const itemIndexInAll = allItems.indexOf(item);
        
        // Skip creating placeholder if it would be at the dragged element's current position
        // This happens when the placeholder would be right after the item before the dragged one
        if (itemIndexInAll === draggedIndex - 1) {
            console.log(`Skipping placeholder after item ${index} (current position of dragged item)`);
            return;
        }
        
        console.log(`Creating placeholder after item ${index}:`, item.id);
        this.createDropZone(container, item);
    });
    
    console.log('Total placeholders created:', container.querySelectorAll('.drop-placeholder').length);
};

RetrospectiveBoard.prototype.createDropZone = function(container, afterElement) {
    const placeholder = document.createElement('div');
    placeholder.className = 'drop-placeholder';
    placeholder.style.height = '40px';
    placeholder.style.backgroundColor = '#f0f0f0'; // Light gray background
    placeholder.style.margin = '5px 0 13px 0';
    placeholder.style.borderRadius = '4px';
    placeholder.style.border = '2px dashed #999'; // Dark gray dashed border
    placeholder.style.transition = 'background-color 0.2s, border-color 0.2s';
    placeholder.style.pointerEvents = 'auto'; // Ensure placeholder can receive events
    
    // Highlight on hover - make it blue with opacity
    placeholder.addEventListener('mouseenter', function() {
        this.style.backgroundColor = 'rgba(0, 123, 255, 0.5)'; // Blue with 50% opacity
        this.style.borderColor = '#007bff';
    });
    placeholder.addEventListener('mouseleave', function() {
        this.style.backgroundColor = '#f0f0f0';
        this.style.borderColor = '#999';
    });
    
    // Allow drop on placeholder
    placeholder.addEventListener('dragover', (e) => {
        e.preventDefault();
        // Make it blue with opacity when dragging over
        placeholder.style.backgroundColor = 'rgba(0, 123, 255, 0.5)'; // Blue with 50% opacity
        placeholder.style.borderColor = '#007bff';
    });
    
    placeholder.addEventListener('dragleave', (e) => {
        // Reset to gray when leaving
        placeholder.style.backgroundColor = '#f0f0f0';
        placeholder.style.borderColor = '#999';
    });
    
    placeholder.addEventListener('drop', (e) => {
        console.log('DROP on PLACEHOLDER');
        e.preventDefault();
        e.stopPropagation();
        
        // Set flag to indicate drop was executed
        this.dropExecuted = true;
        
        const draggedItem = document.querySelector('.dragging');
        if (!draggedItem) {
            console.log('No dragged item on placeholder drop');
            return;
        }
        
        const category = container.id.replace('Items', '');
        
        // Calculate new order based on placeholder position
        const newOrder = this.calculateOrderFromPlaceholder(container, placeholder, draggedItem);
        console.log('New order calculated:', newOrder);
        
        const orderChanged = this.hasOrderChanged(category, newOrder.orderedElements);
        console.log('Order changed?', orderChanged);
        
        if (orderChanged) {
            console.log('Order changed via placeholder drop');
            this.updateUIOrder(category, newOrder.orderedElements);
            this.reorderItemsInColumn(category, newOrder.itemIds, newOrder.groupIds, newOrder.orderedElements);
        } else {
            console.log('Order NOT changed, skipping update');
        }
        
        // Remove dragging class and cleanup
        draggedItem.classList.remove('dragging');
        this.hideAllDropZones();
        container.classList.remove('drag-over');
    });
    
    // Insert placeholder
    if (afterElement === null) {
        container.insertBefore(placeholder, container.firstChild);
    } else {
        afterElement.insertAdjacentElement('afterend', placeholder);
    }
};

RetrospectiveBoard.prototype.hideAllDropZones = function() {
    const allPlaceholders = document.querySelectorAll('.drop-placeholder');
    allPlaceholders.forEach(p => p.remove());
};

RetrospectiveBoard.prototype.calculateOrderFromPlaceholder = function(container, placeholder, draggedItem) {
    const orderedElements = []; // Array of {type: 'item'|'group', id: number}
    
    console.log('=== CALCULATE ORDER FROM PLACEHOLDER ===');
    console.log('Dragged item:', draggedItem.id, 'is group?', draggedItem.classList.contains('combined-group'));
    
    // Get all children including placeholders
    const children = [...container.children];
    console.log('Total children in container:', children.length);
    
    // Find placeholder index
    const placeholderIndex = children.indexOf(placeholder);
    console.log('Placeholder index:', placeholderIndex);
    
    // Build order array
    children.forEach((child, index) => {
        console.log(`Child ${index}:`, child.id || child.className, 'is placeholder?', child.classList.contains('drop-placeholder'), 'is dragging?', child.classList.contains('dragging'));
        
        if (child.classList.contains('drop-placeholder')) {
            // Insert dragged item at placeholder position
            if (child === placeholder) {
                console.log('  -> This is THE placeholder, inserting dragged item');
                if (draggedItem.classList.contains('combined-group')) {
                    const groupId = parseInt(draggedItem.dataset.groupId);
                    console.log('  -> Adding group:', groupId);
                    orderedElements.push({type: 'group', id: groupId});
                } else {
                    const itemId = parseInt(draggedItem.dataset.itemId);
                    console.log('  -> Adding item:', itemId);
                    orderedElements.push({type: 'item', id: itemId});
                }
            } else {
                console.log('  -> Different placeholder, skipping');
            }
        } else if (!child.classList.contains('dragging')) {
            // Add existing items/groups
            if (child.classList.contains('combined-group')) {
                const groupId = parseInt(child.dataset.groupId);
                console.log('  -> Adding existing group:', groupId);
                orderedElements.push({type: 'group', id: groupId});
            } else if (child.dataset.itemId) {
                const itemId = parseInt(child.dataset.itemId);
                console.log('  -> Adding existing item:', itemId);
                orderedElements.push({type: 'item', id: itemId});
            }
        } else {
            console.log('  -> Skipping (is dragging)');
        }
    });
    
    // Separate into itemIds and groupIds for backwards compatibility with backend
    const itemIds = orderedElements.filter(el => el.type === 'item').map(el => el.id);
    const groupIds = orderedElements.filter(el => el.type === 'group').map(el => el.id);
    
    console.log('Final order calculated:', {itemIds, groupIds, orderedElements});
    return { itemIds, groupIds, orderedElements };
};

RetrospectiveBoard.prototype.showDropPlaceholder = function(container, y) {
    // Check if cursor is over an item (for grouping) - if so, don't show placeholder
    const items = [...container.querySelectorAll('.review-item:not(.dragging)')];
    const overItem = items.find(item => {
        const box = item.getBoundingClientRect();
        return y >= box.top && y <= box.bottom;
    });
    
    // If over an item, hide placeholder to allow grouping
    if (overItem) {
        this.hideDropPlaceholder(container);
        return;
    }
    
    // Remove existing placeholder
    this.hideDropPlaceholder(container);
    
    const afterElement = this.getDragAfterElement(container, y);
    
    // Create placeholder element
    const placeholder = document.createElement('div');
    placeholder.className = 'drop-placeholder';
    placeholder.style.height = '100px';
    placeholder.style.backgroundColor = '#007bff';
    placeholder.style.margin = '15px 0';
    placeholder.style.borderRadius = '4px';
    placeholder.style.opacity = '0.5';
    
    // IMPORTANT: Allow drop on placeholder
    placeholder.addEventListener('dragover', (e) => {
        e.preventDefault();
        console.log('DRAGOVER on PLACEHOLDER');
    });
    
    placeholder.addEventListener('drop', (e) => {
        console.log('DROP on PLACEHOLDER');
        e.preventDefault();
        e.stopPropagation();
        
        // Set flag to indicate drop was executed
        this.dropExecuted = true;
        
        // Manually call drop logic since we can't easily pass modified event
        const draggedItem = document.querySelector('.dragging');
        if (!draggedItem) {
            console.log('No dragged item on placeholder drop');
            return;
        }
        
        const category = container.id.replace('Items', '');
        
        // This is reordering (drop on placeholder)
        const newOrder = this.calculateNewOrder(container, draggedItem, e.clientY);
        const orderChanged = this.hasOrderChanged(category, newOrder.itemIds, newOrder.groupIds);
        
        if (orderChanged) {
            console.log('Order changed via placeholder drop');
            this.updateUIOrder(category, newOrder.itemIds, newOrder.groupIds);
            this.reorderItemsInColumn(category, newOrder.itemIds, newOrder.groupIds);
        }
        
        // Remove dragging class immediately after processing
        draggedItem.classList.remove('dragging');
        this.hideDropPlaceholder(container);
        container.classList.remove('drag-over');
    });
    
    // Insert placeholder at the correct position
    if (afterElement == null) {
        container.appendChild(placeholder);
    } else {
        container.insertBefore(placeholder, afterElement);
    }
    
    // Store reference for later removal
    container._dropPlaceholder = placeholder;
};

RetrospectiveBoard.prototype.hideDropPlaceholder = function(container) {
    if (container._dropPlaceholder) {
        container._dropPlaceholder.remove();
        container._dropPlaceholder = null;
    }
};

RetrospectiveBoard.prototype.storeInitialOrder = function() {
    // Store the initial order of items and groups for each category
    this.initialOrder = {};
    const categories = ['wrong', 'good', 'improved', 'random'];
    
    categories.forEach(category => {
        const container = document.getElementById(`${category}Items`);
        if (container) {
            const items = Array.from(container.querySelectorAll('.review-item, .combined-group'));
            const orderedElements = [];
            
            items.forEach(item => {
                if (item.classList.contains('combined-group')) {
                    const groupId = item.dataset.groupId;
                    if (groupId) {
                        orderedElements.push({type: 'group', id: parseInt(groupId)});
                    }
                } else {
                    const itemId = item.dataset.itemId;
                    if (itemId) {
                        orderedElements.push({type: 'item', id: parseInt(itemId)});
                    }
                }
            });
            
            this.initialOrder[category] = {
                orderedElements: orderedElements
            };
            
        }
    });
};

RetrospectiveBoard.prototype.hasOrderChanged = function(category, newOrderedElements) {
    if (!this.initialOrder || !this.initialOrder[category]) {
        console.log('No initial order stored, assuming changed');
        return true; // If no initial order stored, assume it changed
    }
    
    const initial = this.initialOrder[category];
    console.log('Initial ordered elements for', category, ':', JSON.stringify(initial.orderedElements));
    console.log('New ordered elements:', JSON.stringify(newOrderedElements));
    
    // Compare the ordered elements array
    const orderChanged = JSON.stringify(initial.orderedElements) !== JSON.stringify(newOrderedElements);
    
    console.log('Order changed?', orderChanged);
    
    return orderChanged;
};

RetrospectiveBoard.prototype.calculateNewOrder = function(container, draggedItem, clientY) {
    const items = Array.from(container.querySelectorAll('.review-item'));
    const draggedItemId = draggedItem.dataset.itemId ? parseInt(draggedItem.dataset.itemId) : null;
    const draggedGroupId = draggedItem.dataset.groupId ? parseInt(draggedItem.dataset.groupId) : null;
    const isDraggedGroup = draggedItem.classList.contains('combined-group');
    
    // Remove the dragged item from the list
    const otherItems = items.filter(item => item !== draggedItem);
    
    // Find where to insert the dragged item based on the drop position
    const afterElement = this.getDragAfterElement(container, clientY);
    
    let newItems = [];
    let inserted = false;
    
    for (let item of otherItems) {
        if (item === afterElement && !inserted) {
            // Insert dragged item before this element
            newItems.push(draggedItem);
            inserted = true;
        }
        newItems.push(item);
    }
    
    // If not inserted yet, add at the end
    if (!inserted) {
        newItems.push(draggedItem);
    }
    
    // Extract IDs in the new order
    const itemIds = [];
    const groupIds = [];
    const orderedElements = []; // New: array of {type: 'item'|'group', id: number}
    
    newItems.forEach(item => {
        if (item.classList.contains('combined-group')) {
            const groupId = item.dataset.groupId;
            if (groupId) {
                groupIds.push(parseInt(groupId));
                orderedElements.push({type: 'group', id: parseInt(groupId)});
            }
        } else {
            const itemId = item.dataset.itemId;
            if (itemId) {
                itemIds.push(parseInt(itemId));
                orderedElements.push({type: 'item', id: parseInt(itemId)});
            }
        }
    });
    
    console.log('calculateNewOrder - draggedItemId:', draggedItemId, 'draggedGroupId:', draggedGroupId);
    console.log('calculateNewOrder - afterElement:', afterElement);
    console.log('calculateNewOrder - newItems length:', newItems.length);
    console.log('calculateNewOrder - result:', { itemIds, groupIds, orderedElements });
    
    return { itemIds, groupIds, orderedElements };
};

RetrospectiveBoard.prototype.isInGroupingZone = function(e, dropTarget) {
    if (!dropTarget) return false;
    
    const rect = dropTarget.getBoundingClientRect();
    const x = e.clientX - rect.left;
    const y = e.clientY - rect.top;
    
    // Define grouping zone as the center 50% of the item (both width and height)
    const centerX = rect.width / 2;
    const centerY = rect.height / 2;
    const groupingZoneWidth = rect.width * 0.5; // 50% of width
    const groupingZoneHeight = rect.height * 0.5; // 50% of height
    
    const isInCenterX = Math.abs(x - centerX) < (groupingZoneWidth / 2);
    const isInCenterY = Math.abs(y - centerY) < (groupingZoneHeight / 2);
    
    // Only consider it grouping if drop is in the center zone
    return isInCenterX && isInCenterY;
};

RetrospectiveBoard.prototype.showGroupingFeedback = function(targetItem) {
    // Remove any existing feedback
    this.hideAllFeedback();
    
    // Add grouping visual feedback
    targetItem.classList.add('grouping-target');
    targetItem.style.border = '3px solid #28a745';
    targetItem.style.backgroundColor = 'rgba(40, 167, 69, 0.1)';
};

RetrospectiveBoard.prototype.showReorderingFeedback = function(container, clientY) {
    // Remove any existing feedback
    this.hideAllFeedback();
    
    // Show reordering placeholder
    this.showDropPlaceholder(container, clientY);
};

RetrospectiveBoard.prototype.hideAllFeedback = function() {
    // Remove grouping feedback
    const groupingTargets = document.querySelectorAll('.grouping-target');
    groupingTargets.forEach(item => {
        item.classList.remove('grouping-target');
        item.style.border = '';
        item.style.backgroundColor = '';
    });
    
    // Remove reordering feedback
    const containers = document.querySelectorAll('.review-column');
    containers.forEach(container => {
        this.hideDropPlaceholder(container);
    });
};

RetrospectiveBoard.prototype.updateUIOrder = function(category, orderedElements) {
    const container = document.getElementById(`${category}Items`);
    if (!container) {
        console.log('Container not found for category:', category);
        return;
    }
    
    console.log('Updating UI order for category:', category, 'orderedElements:', orderedElements);
    
    // Validate orderedElements
    if (!orderedElements || orderedElements.length === 0) {
        console.warn('No elements to reorder, skipping UI update');
        return;
    }
    
    // Get all current items and groups in the container
    const allItems = Array.from(container.querySelectorAll('.review-item, .combined-group'));
    
    // Create a map for quick lookup
    const elementMap = new Map();
    
    allItems.forEach(item => {
        if (item.classList.contains('combined-group')) {
            const groupId = item.dataset.groupId;
            if (groupId) {
                elementMap.set(`group-${groupId}`, item);
            }
        } else {
            const itemId = item.dataset.itemId;
            if (itemId) {
                elementMap.set(`item-${itemId}`, item);
            }
        }
    });
    
    // Remove only items/groups, not placeholders or other elements
    allItems.forEach(item => item.remove());
    
    // Add elements in the new order
    let index = 0;
    orderedElements.forEach(element => {
        const key = `${element.type}-${element.id}`;
        const domElement = elementMap.get(key);
        if (domElement) {
            container.appendChild(domElement);
            index++;
            console.log(`Added ${element.type} ${element.id} to position ${index}`);
        } else {
            console.log(`Element not found in map: ${key}`);
        }
    });
    
    console.log('UI order updated for category:', category, 'with', index, 'elements');
};

RetrospectiveBoard.prototype.initDragHandlers = function() {
    // Only initialize handlers once
    if (this.dragHandlersInitialized) return;
    this.dragHandlersInitialized = true;
    
    this.handleDragStart = (e) => {
        console.log('=== DRAG START ===');
        console.log('Target:', e.target.id);
        
        if (e.target.classList.contains('review-item') || e.target.classList.contains('combined-group')) {
            this.draggedItem = e.target;
            e.dataTransfer.effectAllowed = 'move';
            this.dropExecuted = false; // Reset drop flag
            
            // Add dragging class immediately (for the original element that stays in place)
            e.target.classList.add('dragging');
            console.log('Dragging class added to:', e.target.id);
            
            // Store initial order when drag starts
            this.storeInitialOrder();
            
            // Set data for both items and groups
            if (e.target.classList.contains('combined-group')) {
                e.dataTransfer.setData('text/plain', 'group');
            } else {
                e.dataTransfer.setData('text/plain', 'item');
            }
            
            // Show ALL drop zones AFTER dragstart completes (async)
            const category = e.target.dataset.category;
            const draggedElement = e.target;
            setTimeout(() => {
                console.log('Creating drop zones for category:', category);
                const container = document.getElementById(`${category}Items`);
                console.log('Container found:', container?.id);
                if (container) {
                    this.showAllDropZones(container, draggedElement);
                } else {
                    console.log('Container NOT found for category:', category);
                }
            }, 0);
        }
    };

    this.handleDragEnd = (e) => {
        console.log('=== DRAG END ===');
        console.log('Element with dragging class:', document.querySelector('.dragging')?.id);
        console.log('Drop executed:', this.dropExecuted);
        
        // Immediate cleanup of placeholders and visual feedback
        this.hideAllDropZones();
        const allContainers = document.querySelectorAll('.items-container');
        allContainers.forEach(container => {
            container.classList.remove('drag-over');
        });
        
        // Always remove dragging class to ensure UI cleanup
        const stillDragging = document.querySelector('.dragging');
        if (stillDragging) {
            console.log('Removing dragging class from:', stillDragging.id);
            stillDragging.classList.remove('dragging');
        }
        
        // Reset flags
        this.draggedItem = null;
        this.dropExecuted = false;
    };

    this.handleDragOver = (e) => {
        // console.log('DRAGOVER on:', e.currentTarget.id); // Too verbose
        e.preventDefault();
        // e.stopPropagation(); // DON'T stop propagation
        e.dataTransfer.dropEffect = 'move';
        
        const container = e.currentTarget;
        const category = container.id.replace('Items', '');
        container.classList.add('drag-over');
        
        // Placeholders are now shown at drag start, no need to show/hide dynamically
        // if (this.draggedItem) {
        //     this.showDropPlaceholder(container, e.clientY);
        // }
    };

    this.handleDragLeave = (e) => {
        const container = e.currentTarget;
        if (!container.contains(e.relatedTarget)) {
            container.classList.remove('drag-over');
            // Placeholders remain visible until drop or dragend
            // this.hideDropPlaceholder(container);
        }
    };

    this.handleDrop = (e) => {
        console.log('=== DROP EVENT ===');
        console.log('Drop on container:', e.currentTarget.id);
        console.log('Drop target:', e.target.id);
        
        e.preventDefault();
        e.stopPropagation();
        const container = e.currentTarget;
        const category = container.id.replace('Items', '');
        
        container.classList.remove('drag-over');
        // Placeholders will be cleaned up in dragend
        // this.hideDropPlaceholder(container);

        // Find the dragged item by class instead of this.draggedItem
        const draggedItem = document.querySelector('.dragging');
        console.log('Found dragged item:', draggedItem?.id);
        console.log('Elements with .dragging class:', document.querySelectorAll('.dragging').length);
        
        if (!draggedItem) {
            console.log('No dragged item found, returning');
            return;
        }

        const draggedItemId = draggedItem.dataset.itemId ? parseInt(draggedItem.dataset.itemId) : null;
        const draggedCategory = draggedItem.dataset.category;
        const isDraggedGroup = draggedItem.classList.contains('combined-group');

        // Check if we're reordering within the same column
        if (draggedCategory === category) {
            // Check if we're dropping on another item (grouping) or empty space (reordering)
            const dropTarget = e.target.closest('.review-item');
            
            if (dropTarget && dropTarget !== draggedItem) {
                // This is grouping
                const targetItemId = dropTarget.dataset.itemId ? parseInt(dropTarget.dataset.itemId) : null;
                const isTargetGroup = dropTarget.classList.contains('combined-group');
                
                if (!isDraggedGroup && draggedItemId && targetItemId && !isTargetGroup) {
                    // Create group with these two individual items
                    const targetPosition = this.getItemPositionInColumn(dropTarget, category);
                    this.createGroupFromItems([draggedItemId, targetItemId], category, targetPosition);
                } else if (!isDraggedGroup && draggedItemId && isTargetGroup) {
                    // Add individual item to existing group
                    const groupElement = dropTarget.closest('.review-item.combined-group');
                    if (groupElement) {
                        this.addItemToExistingGroup(draggedItemId, groupElement);
                    }
                } else if (isDraggedGroup && targetItemId && !isTargetGroup) {
                    // Add individual item to dragged group
                    const groupElement = draggedItem;
                    if (groupElement) {
                        this.addItemToExistingGroup(targetItemId, groupElement);
                    }
                }
            } else {
                // This is reordering
                const newOrder = this.calculateNewOrder(container, draggedItem, e.clientY);
                const orderChanged = this.hasOrderChanged(category, newOrder.orderedElements);
                
                if (orderChanged) {
                    console.log('Order changed, updating UI for category:', category);
                    // Update UI immediately
                    this.updateUIOrder(category, newOrder.orderedElements);
                    // Send reorder request to server
                    this.reorderItemsInColumn(category, newOrder.itemIds, newOrder.groupIds, newOrder.orderedElements);
                } else {
                    console.log('Order unchanged for category:', category);
                }
            }
        } else {
            // Cross-column grouping
            const dropTarget = e.target.closest('.review-item');
            
            if (dropTarget && dropTarget !== draggedItem) {
                const targetItemId = dropTarget.dataset.itemId ? parseInt(dropTarget.dataset.itemId) : null;
                const isTargetGroup = dropTarget.classList.contains('combined-group');
                
                if (!isDraggedGroup && draggedItemId && targetItemId && !isTargetGroup) {
                    const targetPosition = this.getItemPositionInColumn(dropTarget, category);
                    this.createGroupFromItems([draggedItemId, targetItemId], category, targetPosition);
                } else if (!isDraggedGroup && draggedItemId && isTargetGroup) {
                    const groupElement = dropTarget.closest('.review-item.combined-group');
                    if (groupElement) {
                        this.addItemToExistingGroup(draggedItemId, groupElement);
                    }
                } else if (isDraggedGroup && targetItemId && !isTargetGroup) {
                    const groupElement = draggedItem;
                    if (groupElement) {
                        this.addItemToExistingGroup(targetItemId, groupElement);
                    }
                }
            }
        }
        
        // Remove dragging class immediately after drop
        draggedItem.classList.remove('dragging');
    };
};

    RetrospectiveBoard.prototype.reorderItemsInColumn = async function(category, itemIds, groupIds, orderedElements) {
        try {
            // Get CSRF token
            const csrfToken = document.querySelector('meta[name="csrf-token-reorder"]')?.getAttribute('content');
        
        const response = await fetch(`/retrospectives/${this.retrospectiveId}/reorder-items`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'X-CSRF-Token': csrfToken || ''
            },
            credentials: 'include',
            body: JSON.stringify({
                category: category,
                itemIds: itemIds,
                groupIds: groupIds,
                orderedElements: orderedElements || [], // Send the full ordered array
                _token: csrfToken
            })
        });

        if (response.ok) {
            const data = await response.json();
            console.log('Reorder successful for category:', category);
            // Don't reload data - the UI should already be in the correct state
            // The WebSocket message will handle updates for other clients
        } else {
            console.error('Failed to reorder items:', response.status);
        }
    } catch (error) {
        console.error('Error reordering items:', error);
    }
};

    RetrospectiveBoard.prototype.addItemToExistingGroup = async function(itemId, groupElement) {
        try {
            const groupId = groupElement.dataset.groupId;
            if (!groupId) return;

            // Get CSRF token
            const csrfToken = document.querySelector('meta[name="csrf-token-add-item"]')?.getAttribute('content');
        
        const response = await fetch(`/retrospectives/${this.retrospectiveId}/add-item-to-group`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'X-CSRF-Token': csrfToken || ''
            },
            credentials: 'include',
            body: JSON.stringify({
                itemId: itemId,
                groupId: parseInt(groupId),
                _token: csrfToken
            })
        });

        if (response.ok) {
            const data = await response.json();
            // Reload review data to show the updated group
            this.loadReviewData();
        } else {
            console.error('Failed to add item to group:', response.status);
        }
    } catch (error) {
        console.error('Error adding item to group:', error);
    }
};

RetrospectiveBoard.prototype.createCombinedGroupElement = function(groupItems, category) {
    const itemDiv = document.createElement('div');
    itemDiv.className = `review-item ${category} combined-group`;
    itemDiv.draggable = this.isFacilitator; // Make groups draggable for facilitators
    
    // Set dataset properties for groups
    itemDiv.dataset.category = category;
    // Group ID will be set by the caller
    
    // Create content with paragraphs separated by dotted line
    const contentHtml = groupItems.map((item, index) => {
        const separator = index < groupItems.length - 1 ? '<div class="item-separator"></div>' : '';
        const separateBtn = this.isFacilitator ? `<button class="separate-item-btn" data-item-id="${item.id}" title="Separate this item">↶</button>` : '';
        return `<div class="item-paragraph">${item.content}${separateBtn}</div>${separator}`;
    }).join('');
    
    // Get group ID from the first item (will be set properly by caller)
    const groupId = groupItems[0]?.group_id || 'temp';
    
    itemDiv.innerHTML = `
        <div class="review-item-content">${contentHtml}</div>
        <div class="voting-controls" style="display: none;">
            <button class="vote-btn vote-decrease" data-group-id="${groupId}" data-action="decrease">-</button>
            <input type="number" class="vote-input" data-group-id="${groupId}" value="0" readonly min="0" max="2">
            <button class="vote-btn vote-increase" data-group-id="${groupId}" data-action="increase">+</button>
        </div>
    `;

    // Add event listeners for separate buttons (only for facilitators)
    if (this.isFacilitator) {
        const separateButtons = itemDiv.querySelectorAll('.separate-item-btn');
        separateButtons.forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const itemId = parseInt(btn.dataset.itemId);
                this.separateItemFromGroup(itemId, category);
            });
        });
    }

    return itemDiv;
};

RetrospectiveBoard.prototype.createReviewItemElement = function(item) {
    const itemDiv = document.createElement('div');
    itemDiv.className = `review-item ${item.category}`;
    itemDiv.id = `item-${item.id}`;
    itemDiv.draggable = this.isFacilitator;
    itemDiv.dataset.itemId = item.id;
    itemDiv.dataset.category = item.category;

    itemDiv.innerHTML = `
        <div class="review-item-content">${item.content}</div>
        <div class="voting-controls" style="display: none;">
            <button class="vote-btn vote-decrease" data-item-id="${item.id}" data-action="decrease">-</button>
            <input type="number" class="vote-input" data-item-id="${item.id}" value="0" readonly min="0" max="2">
            <button class="vote-btn vote-increase" data-item-id="${item.id}" data-action="increase">+</button>
        </div>
    `;

    return itemDiv;
};

RetrospectiveBoard.prototype.initReviewDragAndDrop = function() {
    console.log('=== INIT REVIEW DRAG AND DROP ===');
    console.log('isFacilitator:', this.isFacilitator);
    
    if (!this.isFacilitator) return;

    // Initialize drag handlers once
    if (!this.dragHandlersInitialized) {
        console.log('Initializing drag handlers...');
        this.initDragHandlers();
    }

    const categories = ['wrong', 'good', 'improved', 'random'];
    
    categories.forEach(category => {
        const container = document.getElementById(`${category}Items`);
        if (!container) return;
        
        // Remove existing event listeners to prevent duplicates
        container.removeEventListener('dragover', this.handleDragOver);
        container.removeEventListener('dragleave', this.handleDragLeave);
        container.removeEventListener('drop', this.handleDrop);

        // Add event listeners to container
        container.addEventListener('dragover', this.handleDragOver);
        container.addEventListener('dragleave', this.handleDragLeave);
        container.addEventListener('drop', this.handleDrop);
        
        // Debug: test if drop event is being registered
        container.addEventListener('drop', (e) => {
            console.log('DROP EVENT CAPTURED on:', container.id);
        }, true);
        
        // Add drag listeners to individual items
        const items = container.querySelectorAll('.review-item, .combined-group');
        console.log(`Found ${items.length} items in ${category}`);
        items.forEach(item => {
            item.removeEventListener('dragstart', this.handleDragStart);
            item.removeEventListener('dragend', this.handleDragEnd);
            item.addEventListener('dragstart', this.handleDragStart);
            item.addEventListener('dragend', this.handleDragEnd);
            
            // Also add drop listener to items - for grouping
            item.addEventListener('drop', (e) => {
                console.log('DROP on ITEM:', item.id);
            e.preventDefault();
                e.stopPropagation(); // Don't let it bubble to container
                
                // Set flag to indicate drop was executed
                this.dropExecuted = true;
                
                const draggedItem = document.querySelector('.dragging');
                if (!draggedItem || draggedItem === item) {
                    console.log('No valid dragged item for grouping');
                    return;
                }
                
                const container = item.closest('.items-container');
                const category = container.id.replace('Items', '');
                
                // This is grouping (drop on another item)
                const draggedItemId = draggedItem.dataset.itemId ? parseInt(draggedItem.dataset.itemId) : null;
                const targetItemId = item.dataset.itemId ? parseInt(item.dataset.itemId) : null;
                const isDraggedGroup = draggedItem.classList.contains('combined-group');
                const isTargetGroup = item.classList.contains('combined-group');
                
                console.log('Grouping - draggedItemId:', draggedItemId, 'targetItemId:', targetItemId, 'isDraggedGroup:', isDraggedGroup, 'isTargetGroup:', isTargetGroup);
                
                if (!isDraggedGroup && draggedItemId && targetItemId && !isTargetGroup) {
                    // Create group with these two individual items
                    // Group should be at the position of the target item (the one we dropped on)
                    const targetPosition = this.getItemPositionInColumn(item, category);
                    console.log('Creating group at target position:', targetPosition);
                    this.createGroupFromItems([draggedItemId, targetItemId], category, targetPosition);
                } else if (!isDraggedGroup && draggedItemId && isTargetGroup) {
                    // Add individual item to existing group
                    console.log('Adding item to existing group');
                    this.addItemToExistingGroup(draggedItemId, item);
                } else if (isDraggedGroup && targetItemId && !isTargetGroup) {
                    // Add individual target item to dragged group
                    console.log('Adding target item to dragged group');
                    this.addItemToExistingGroup(targetItemId, draggedItem);
                        } else {
                    console.log('No valid grouping scenario');
                        }

                // Remove dragging class immediately after processing
                draggedItem.classList.remove('dragging');
                container.classList.remove('drag-over');
            });

            // Add dragover to items to allow drop
            item.addEventListener('dragover', (e) => {
                console.log('DRAGOVER on ITEM:', item.id);
            e.preventDefault();
                // DON'T stop propagation - let it bubble to container
            });
        });
    });
};

RetrospectiveBoard.prototype.updateItemOrder = function(category) {
    const container = document.getElementById(`${category}Items`);
    if (!container) return;
    
    const items = container.querySelectorAll('.review-item, .combined-group');
                    const itemIds = [];
                    const groupIds = [];

                    items.forEach(item => {
                        if (item.classList.contains('combined-group')) {
            groupIds.push(parseInt(item.dataset.groupId));
                        } else {
            itemIds.push(parseInt(item.dataset.itemId));
        }
    });
    
    // Send to backend
    fetch(`/retrospective/${window.retrospectiveId}/reorder-items`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
            category: category,
            item_ids: itemIds,
            group_ids: groupIds
        })
    }).then(response => response.json())
    .then(data => {
        console.log('Order updated:', data);
    });
};

RetrospectiveBoard.prototype.checkForGrouping = function(item, x, y) {
    const itemId = parseInt(item.id.replace('item-', ''));
    const threshold = 100; // Distance threshold for grouping

    // Find nearby items
    const nearbyItems = this.reviewItems.filter(otherItem => {
        if (otherItem.id === itemId) return false;
        
        const otherElement = document.getElementById(`item-${otherItem.id}`);
        if (!otherElement) return false;

        const otherRect = otherElement.getBoundingClientRect();
        const distance = Math.sqrt(
            Math.pow(otherRect.left - x, 2) + Math.pow(otherRect.top - y, 2)
        );
        
        return distance < threshold;
    });

    if (nearbyItems.length > 0) {
        // Create group with nearby items
        this.createGroupFromItems([itemId, ...nearbyItems.map(i => i.id)], x, y);
    }
};

RetrospectiveBoard.prototype.getItemPositionInColumn = function(targetElement, category) {
    // Find the items container (not the column)
    const container = document.getElementById(`${category}Items`);
    if (!container) {
        console.log('Container not found for category:', category);
        return 0;
    }
    
    // Get all items and groups in the container in DOM order
    const items = Array.from(container.querySelectorAll('.review-item, .combined-group'));
    
    // Find the index of the target element
    const targetIndex = items.indexOf(targetElement);
    
    console.log('getItemPositionInColumn - target:', targetElement.id, 'index:', targetIndex, 'total items:', items.length);
    
    // Return the position (index) of the target element
    return targetIndex >= 0 ? targetIndex : items.length;
};

RetrospectiveBoard.prototype.createGroupFromItems = async function(itemIds, category, targetPosition = null) {
    try {
        // Get CSRF token
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        
        console.log('CSRF Token:', csrfToken);
        console.log('Request data:', { itemIds, category });
        console.log('All meta tags:', document.querySelectorAll('meta[name*="csrf"]'));
        
        const response = await fetch(`/retrospectives/${this.retrospectiveId}/create-group`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'X-CSRF-Token': csrfToken || ''
            },
            credentials: 'include',
            body: JSON.stringify({
                itemIds: itemIds,
                category: category,
                targetPosition: targetPosition,
                _token: csrfToken
            })
        });
        
        console.log('Response status:', response.status);
        console.log('Response headers:', response.headers);
        
        if (!response.ok) {
            const errorText = await response.text();
            console.log('Response text:', errorText);
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const result = await response.json();
        console.log('Group created successfully:', result);
        
        // Don't reload here - WebSocket will trigger reload via handleGroupCreated
        // if (result.success) {
        //     await this.loadReviewData();
        // }
        
    } catch (error) {
        console.error('Failed to create group:', error);
    }
};

    RetrospectiveBoard.prototype.separateItemFromGroup = async function(itemId, category) {
        try {
            // Get CSRF token
            const csrfToken = document.querySelector('meta[name="csrf-token-separate"]')?.getAttribute('content');
        
        const response = await fetch(`/retrospectives/${this.retrospectiveId}/separate-item`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'X-CSRF-Token': csrfToken || ''
            },
            credentials: 'include',
            body: JSON.stringify({
                itemId: itemId,
                _token: csrfToken
            })
        });

        if (response.ok) {
            const data = await response.json();
            // Reload review data to show the separated item
            this.loadReviewData();
        } else {
            console.error('Failed to separate item:', response.status);
        }
    } catch (error) {
        console.error('Error separating item:', error);
    }
};

RetrospectiveBoard.prototype.checkForGrouping = function(item, x, y) {
    const itemId = parseInt(item.id.replace('item-', ''));
    const threshold = 100; // Distance threshold for grouping

    // Find nearby items
    const nearbyItems = this.reviewItems.filter(otherItem => {
        if (otherItem.id === itemId) return false;
        
        const otherElement = document.getElementById(`item-${otherItem.id}`);
        if (!otherElement) return false;

        const otherX = parseInt(otherElement.style.left) || 0;
        const otherY = parseInt(otherElement.style.top) || 0;

        const distance = Math.sqrt(Math.pow(x - otherX, 2) + Math.pow(y - otherY, 2));
        return distance < threshold;
    });

    if (nearbyItems.length > 0) {
        // Create or update group
        this.createGroupFromItems([itemId, ...nearbyItems.map(i => i.id)], x, y);
    }
};

RetrospectiveBoard.prototype.getItemPositionInColumn = function(targetElement, category) {
    // Find the items container (not the column)
    const container = document.getElementById(`${category}Items`);
    if (!container) {
        console.log('Container not found for category:', category);
        return 0;
    }
    
    // Get all items and groups in the container in DOM order
    const items = Array.from(container.querySelectorAll('.review-item, .combined-group'));
    
    // Find the index of the target element
    const targetIndex = items.indexOf(targetElement);
    
    console.log('getItemPositionInColumn - target:', targetElement.id, 'index:', targetIndex, 'total items:', items.length);
    
    // Return the position (index) of the target element
    return targetIndex >= 0 ? targetIndex : items.length;
};

RetrospectiveBoard.prototype.createGroupFromItems = async function(itemIds, category, targetPosition = null) {
    try {
        // Get CSRF token
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        
        console.log('CSRF Token:', csrfToken);
        console.log('Request data:', { itemIds, category });
        console.log('All meta tags:', document.querySelectorAll('meta[name*="csrf"]'));
        
        const response = await fetch(`/retrospectives/${this.retrospectiveId}/create-group`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'X-CSRF-Token': csrfToken || ''
            },
            credentials: 'include',
            body: JSON.stringify({
                itemIds: itemIds,
                category: category,
                targetPosition: targetPosition,
                _token: csrfToken
            })
        });
        
        console.log('Response status:', response.status);
        console.log('Response headers:', response.headers);
        
        if (!response.ok) {
            const text = await response.text();
            console.log('Response text:', text);
        }

        if (response.ok) {
            const data = await response.json();
            // Reload review data to show the new group
            this.loadReviewData();
        } else {
            console.error('Failed to create group:', response.status);
        }
    } catch (error) {
        console.error('Error creating group:', error);
    }
};

    RetrospectiveBoard.prototype.separateItemFromGroup = async function(itemId, category) {
        try {
            // Get CSRF token
            const csrfToken = document.querySelector('meta[name="csrf-token-separate"]')?.getAttribute('content');
        
        const response = await fetch(`/retrospectives/${this.retrospectiveId}/separate-item`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'X-CSRF-Token': csrfToken || ''
            },
            credentials: 'include',
            body: JSON.stringify({
                itemId: itemId,
                _token: csrfToken
            })
        });

        if (response.ok) {
            const data = await response.json();
            // Reload review data to show the separated item
            this.loadReviewData();
        } else {
            console.error('Failed to separate item:', response.status);
        }
    } catch (error) {
        console.error('Error separating item:', error);
    }
};


// Card Zoom Functionality
RetrospectiveBoard.prototype.initCardZoom = function() {
    const zoomButtons = document.querySelectorAll('.btn-add-action');
    const markDiscussedButtons = document.querySelectorAll('.btn-mark-discussed');
    const overlay = document.getElementById('zoomOverlay');
    const zoomedCard = document.getElementById('zoomedCard');
    const closeBtn = document.getElementById('closeZoom');

    zoomButtons.forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const cardElement = btn.closest('.discussion-card');
            this.zoomCard(cardElement);
        });
    });

    markDiscussedButtons.forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const cardElement = btn.closest('.discussion-card');
            const itemId = btn.dataset.id;
            const itemType = btn.dataset.type;
            this.markAsDiscussed(cardElement, itemId, itemType);
        });
    });

    closeBtn.addEventListener('click', () => {
        this.closeZoom();
    });

    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) {
            this.closeZoom();
        }
    });

    // Close on ESC key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && overlay.classList.contains('active')) {
            this.closeZoom();
        }
    });
};

RetrospectiveBoard.prototype.zoomCard = function(cardElement) {
    const overlay = document.getElementById('zoomOverlay');
    const zoomedCard = document.getElementById('zoomedCard');
    
    // Clone the card content
    const clonedCard = cardElement.cloneNode(true);
    
    // Remove the action buttons from the cloned card
    const actionsDiv = clonedCard.querySelector('.card-actions');
    if (actionsDiv) {
        actionsDiv.remove();
    }
    
    // Remove hover classes that might interfere
    clonedCard.classList.remove('dragging');
    
    // Clear and append the cloned content
    zoomedCard.innerHTML = '';
    zoomedCard.appendChild(clonedCard);
    
    // Force the card to maintain hover state styles
    clonedCard.style.transform = 'none';
    clonedCard.style.boxShadow = '0 2px 4px rgba(0, 0, 0, 0.05)';
    
    // Show overlay with animation
    overlay.style.display = 'flex';
    setTimeout(() => {
        overlay.classList.add('active');
    }, 10);
    
    // Prevent body scroll
    document.body.style.overflow = 'hidden';
};

RetrospectiveBoard.prototype.closeZoom = function() {
    const overlay = document.getElementById('zoomOverlay');
    
    overlay.classList.remove('active');
    setTimeout(() => {
        overlay.style.display = 'none';
    }, 300);
    
    // Restore body scroll
    document.body.style.overflow = '';
};

RetrospectiveBoard.prototype.markAsDiscussed = function(cardElement, itemId, itemType, skipSave = false) {
    // Add discussed class for gray out effect
    cardElement.classList.add('discussed');
    
    // Add the discussed badge to the card footer
    const cardFooter = cardElement.querySelector('.card-footer');
    if (cardFooter) {
        const votesContainer = cardFooter.querySelector('div');
        if (votesContainer && !votesContainer.querySelector('.discussed-badge')) {
            const badge = document.createElement('span');
            badge.className = 'discussed-badge';
            badge.textContent = '✓ Discussed';
            votesContainer.appendChild(badge);
        }
    }
    
    // Hide the "Mark as Discussed" button
    const markBtn = cardElement.querySelector('.btn-mark-discussed');
    if (markBtn) {
        markBtn.style.display = 'none';
    }
    
    // Get the container
    const container = cardElement.parentElement;
    
    // Animate card moving to bottom
    const cards = Array.from(container.querySelectorAll('.discussion-card'));
    const currentIndex = cards.indexOf(cardElement);
    const lastCard = cards[cards.length - 1];
    
    // Calculate distance to move
    if (lastCard && lastCard !== cardElement) {
        const cardRect = cardElement.getBoundingClientRect();
        const lastCardRect = lastCard.getBoundingClientRect();
        const distance = lastCardRect.bottom - cardRect.top;
        
        // Animate sliding down
        cardElement.style.transition = 'transform 0.6s ease, opacity 0.3s ease, filter 0.3s ease';
        cardElement.style.transform = `translateY(${distance}px)`;
        cardElement.style.opacity = '0.5';
        
        // After animation, move to end of container
        setTimeout(() => {
            cardElement.style.transition = 'none';
            cardElement.style.transform = '';
            // Don't reset opacity - let CSS class handle it
            container.appendChild(cardElement);
            
            // Trigger reflow
            cardElement.offsetHeight;
            
            // Remove inline styles to let CSS class take over
            setTimeout(() => {
                cardElement.style.opacity = '';
                cardElement.style.transition = '';
            }, 10);
        }, 600);
    }
    
    // Send to backend to persist discussed state (unless we received this update via WebSocket)
    if (!skipSave) {
        this.saveDiscussedState(itemId, itemType);
    }
};

RetrospectiveBoard.prototype.saveDiscussedState = async function(itemId, itemType) {
    try {
        // Get CSRF token
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        
        const response = await fetch(`/retrospectives/${this.retrospectiveId}/mark-discussed`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrfToken || '',
                'Accept': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify({
                itemId: itemId,
                itemType: itemType
            })
        });

        if (!response.ok) {
            const responseText = await response.text();
            console.error(`Failed to save discussed state: ${response.status} - ${responseText}`);
        }
    } catch (error) {
        console.error('Error saving discussed state:', error);
    }
};

// Action Item Management
RetrospectiveBoard.prototype.showActionForm = function() {
    document.getElementById('actionFormContainer').style.display = 'block';
    document.getElementById('showActionFormBtn').style.display = 'none';
};

RetrospectiveBoard.prototype.hideActionForm = function() {
    document.getElementById('actionFormContainer').style.display = 'none';
    document.getElementById('showActionFormBtn').style.display = 'block';
    document.getElementById('addActionForm').reset();
};

RetrospectiveBoard.prototype.handleAddAction = async function(e) {
    e.preventDefault();
    
    const description = document.getElementById('actionDescription').value.trim();
    const assignedToId = document.getElementById('actionAssignee').value;
    const dueDate = document.getElementById('actionDueDate').value;
    
    // DEBUG: Log what we're sending
    
    if (!description) {
        this.showMessage('Please enter a description', 'error');
        return;
    }
    
    const requestData = {
        description: description,
        assignedToId: assignedToId || null,
        dueDate: dueDate || null
    };
    
    
    try {
        const response = await fetch(`/retrospectives/${this.retrospectiveId}/add-action`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify(requestData)
        });
        
        if (response.ok) {
            const data = await response.json();
            this.showMessage('Action item added successfully!', 'success');
            this.hideActionForm();
            // Reload page to show new action
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        } else {
            const errorText = await response.text();
            
            try {
                const errorData = JSON.parse(errorText);
                this.showMessage(errorData.message || 'Failed to add action item', 'error');
            } catch (e) {
                this.showMessage('Server error - check console for details', 'error');
            }
        }
    } catch (error) {
        console.error('DEBUG JS - Network error:', error);
        console.error('DEBUG JS - Error message:', error.message);
        this.showMessage('Network error - check console for details', 'error');
    }
};

// User Connectivity Polling
RetrospectiveBoard.prototype.startUserConnectivityPolling = function() {
    // Call immediately to set initial state
    this.heartbeatConnectedUsers();
    
    // Set up interval to keep users "alive" and check other users
    this.userPollingInterval = setInterval(() => {
        this.heartbeatConnectedUsers();
    }, 30000); // Every 30 seconds
};

RetrospectiveBoard.prototype.heartbeatConnectedUsers = async function() {
    try {
        const response = await fetch(`/retrospectives/${this.retrospectiveId}/connected-users`, {
            method: 'GET',
            credentials: 'include',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        if (response.ok) {
            const data = await response.json();
            this.updateConnectedUsers(data.users);
        }
    } catch (error) {
        console.error('Error in heartbeat users:', error);
    }
};


// Cleanup polling on page unload
window.addEventListener('beforeunload', function() {
    if (window.retrospectiveBoard && window.retrospectiveBoard.userPollingInterval) {
        clearInterval(window.retrospectiveBoard.userPollingInterval);
        
        // Notify server that user is leaving
        if (window.retrospectiveBoard && window.retrospectiveBoard.retrospectiveId) {
            navigator.sendBeacon(`/retrospectives/${window.retrospectiveBoard.retrospectiveId}/leave`, '');
        }
    }
});
