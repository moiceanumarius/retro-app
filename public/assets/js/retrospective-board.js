// Retrospective Board JavaScript
class RetrospectiveBoard {
    constructor() {
        this.retrospectiveId = window.retrospectiveId;
        this.isFacilitator = window.isFacilitator;
        this.timerInterval = null;
        this.timerEndTime = null;
        this.eventSource = null;
        this.timerManuallyStopped = false;
        this.isDragging = false;
        this.dragOffset = { x: 0, y: 0 };
        this.reviewItems = [];
        this.reviewGroups = [];
        this.draggedItem = null;
        this.dragStartPosition = { x: 0, y: 0 };
        
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
        
        // Next step button
        const nextStepBtn = document.getElementById('nextStepBtn');
        if (nextStepBtn) {
            nextStepBtn.addEventListener('click', () => this.nextStep());
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
        });
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
    
    startTimerDisplay(duration) {
        const timerDisplay = document.getElementById('timerDisplay');
        const timerTime = document.getElementById('timerTime');
        const timerControls = document.querySelector('.timer-controls');
        const floatingTimer = document.getElementById('floatingTimer');
        const setTimerLabel = floatingTimer ? floatingTimer.querySelector('.timer-label') : null;
        
        if (timerDisplay && timerTime) {
            // Show floating timer
            if (floatingTimer) {
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
        
        if (timerDisplay && timerTime) {
            // Show floating timer
            if (floatingTimer) {
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
            this.showMessage('Timer expired!', 'error');
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
        
        // Keep floating timer visible for facilitator
        if (floatingTimer && window.isFacilitator) {
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
                this.addPostItCard(category, data.item);
                input.value = '';
                this.updateItemCount(category);
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
        postIt.dataset.id = item.id;
        
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
        content.addEventListener('click', () => {
            this.editPostIt(postIt, itemId, category);
        });
        
        // Delete on click
        deleteBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            this.deletePostIt(itemId, category);
        });
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
                const postIt = document.querySelector(`.post-it[data-id="${itemId}"]`);
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

    async deletePostIt(itemId, category) {
        if (!confirm('Are you sure you want to delete this post?')) {
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
            
            if (response.ok) {
                // Remove the post-it from DOM
                const postIt = document.querySelector(`.post-it[data-id="${itemId}"]`);
                if (postIt) {
                    postIt.remove();
                }
                this.updateItemCount(category);
                this.showMessage('Post deleted successfully!', 'success');
            } else {
                this.showMessage('Failed to delete post', 'error');
            }
        } catch (error) {
            console.error('Error deleting post:', error);
            this.showMessage('Failed to delete post', 'error');
        }
    }

    addEventListenersToExistingPosts() {
        // Add event listeners to existing post-its
        document.querySelectorAll('.post-it').forEach(postIt => {
            const itemId = postIt.dataset.id;
            const category = postIt.closest('.feedback-column').dataset.category;
            
            // Add delete button if not exists
            if (!postIt.querySelector('.post-it-delete')) {
                const deleteBtn = document.createElement('div');
                deleteBtn.className = 'post-it-delete';
                deleteBtn.textContent = '×';
                postIt.appendChild(deleteBtn);
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
        } else {
            this.timerManuallyStopped = true;
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
            
            // Show floating timer and timer display if not already shown
            const floatingTimer = document.getElementById('floatingTimer');
            const timerDisplay = document.getElementById('timerDisplay');
            if (floatingTimer) {
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
            
            if (floatingTimer) {
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
        this.showConnectionStatus('polling');
        
        // Use polling instead of WebSocket for now
        this.startPolling();
    }
    
    startPolling() {
        // Poll every 2 seconds for updates
        this.pollInterval = setInterval(() => {
            this.checkForUpdates();
        }, 2000);
    }
    
    stopPolling() {
        if (this.pollInterval) {
            clearInterval(this.pollInterval);
            this.pollInterval = null;
        }
    }
    
    async checkForUpdates() {
        try {
            const response = await fetch(`/retrospectives/${this.retrospectiveId}/timer-status`, {
                credentials: 'same-origin'
            });
            
            if (response.ok) {
                const data = await response.json();
                this.updateTimerFromServer(data);
            }
        } catch (error) {
            console.error('Error checking for updates:', error);
        }
        
        // Also check for connected users
        this.checkConnectedUsers();
        
        // If in review step, check for review updates
        if (this.isInReviewStep()) {
            this.checkReviewUpdates();
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
        const container = document.getElementById('connectedUsers');
        if (!container) {
            console.error('connectedUsers container not found');
            return;
        }
        
        // Get current user ID
        const currentUser = container.querySelector('.current-user');
        const currentUserId = currentUser ? currentUser.dataset.userId : null;
        
        // Remove all non-current users
        const existingUsers = container.querySelectorAll('.user-item:not(.current-user)');
        existingUsers.forEach(user => user.remove());
        
        // Add other connected users
        users.forEach(user => {
            if (user.id != currentUserId) {
                const userElement = this.createUserElement(user);
                container.appendChild(userElement);
            } else {
            }
        });
        
    }
    
    createUserElement(user) {
        const userDiv = document.createElement('div');
        userDiv.className = 'user-item';
        userDiv.dataset.userId = user.id;
        
        const avatar = user.avatar 
            ? `<img src="/uploads/avatars/${user.avatar}" alt="${user.firstName}">`
            : `<div class="avatar-placeholder">${user.firstName.charAt(0).toUpperCase()}</div>`;
        
        userDiv.innerHTML = `
            <div class="user-avatar">
                ${avatar}
            </div>
            <div class="user-info">
                <div class="user-name">${user.firstName} ${user.lastName}</div>
                <div class="user-status online">Online</div>
            </div>
        `;
        
        return userDiv;
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
    
    handleMercureMessage(data) {
        
        switch (data.type) {
            case 'timer_started':
                this.handleTimerStarted(data);
                break;
            case 'timer_stopped':
                this.handleTimerStopped(data);
                break;
            case 'step_changed':
                this.handleStepChanged(data);
                break;
            case 'item_added':
                this.handleItemAdded(data);
                break;
            default:
        }
    }
    
    handleTimerStarted(data) {
        if (!this.isFacilitator) {
            // Only show timer for non-facilitators
            this.startTimerDisplay(data.duration);
            this.showAddItemForms();
        }
    }

    handleTimerStopped(data) {
        this.timerManuallyStopped = true;
        this.stopTimerDisplay();
        this.hideAddItemForms();
        this.showMessage('Timer stopped by facilitator', 'info');
    }
    
    handleStepChanged(data) {
        this.showMessage(data.message, 'success');
        
        // Reload page to show new step
        setTimeout(() => {
            window.location.reload();
        }, 1500);
    }
    
    handleItemAdded(data) {
        if (!this.isFacilitator) {
            // Only add item for non-facilitators (facilitator already sees it from AJAX response)
            this.addPostItCard(data.item.category, data.item);
            this.updateItemCount(data.item.category);
        }
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

RetrospectiveBoard.prototype.initReviewPhase = function() {
    this.loadReviewData();
    this.initReviewDragAndDrop();
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
        const offset = y - box.top - box.height / 2;
        
        if (offset < 0 && offset > closest.offset) {
            return { offset: offset, element: child };
        } else {
            return closest;
        }
    }, { offset: Number.NEGATIVE_INFINITY }).element;
};

    RetrospectiveBoard.prototype.reorderItemsInColumn = async function(category, itemIds, groupIds) {
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
                _token: csrfToken
            })
        });

        if (response.ok) {
            const data = await response.json();
            // Reload review data to show the new order
            this.loadReviewData();
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
    
    // Group ID will be set by the caller
    
    // Create content with paragraphs separated by dotted line
    const contentHtml = groupItems.map((item, index) => {
        const separator = index < groupItems.length - 1 ? '<div class="item-separator"></div>' : '';
        const separateBtn = this.isFacilitator ? `<button class="separate-item-btn" data-item-id="${item.id}" title="Separate this item">↶</button>` : '';
        return `<div class="item-paragraph">${item.content}${separateBtn}</div>${separator}`;
    }).join('');
    
    itemDiv.innerHTML = `
        <div class="review-item-content">${contentHtml}</div>
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
    `;

    return itemDiv;
};

RetrospectiveBoard.prototype.initReviewDragAndDrop = function() {
    if (!this.isFacilitator) return;

    const categories = ['wrong', 'good', 'improved', 'random'];
    
    categories.forEach(category => {
        const container = document.getElementById(`${category}Items`);
        if (!container) return;
        
        // Remove existing event listeners to prevent duplicates
        container.removeEventListener('dragstart', this.handleDragStart);
        container.removeEventListener('dragend', this.handleDragEnd);
        container.removeEventListener('dragover', this.handleDragOver);
        container.removeEventListener('dragleave', this.handleDragLeave);
        container.removeEventListener('drop', this.handleDrop);

        // Add drag event listeners to items in this column
        this.handleDragStart = (e) => {
            if (e.target.classList.contains('review-item')) {
                this.draggedItem = e.target;
                e.target.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';
                
                // Set data for both items and groups
                if (e.target.classList.contains('combined-group')) {
                    e.dataTransfer.setData('text/plain', 'group');
                } else {
                    e.dataTransfer.setData('text/plain', 'item');
                }
            }
        };

        this.handleDragEnd = (e) => {
            if (e.target.classList.contains('review-item')) {
                e.target.classList.remove('dragging');
                this.draggedItem = null;
            }
        };

        this.handleDragOver = (e) => {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            container.classList.add('drag-over');
            
            // If reordering within the same column, show visual feedback
            if (this.draggedItem && this.draggedItem.dataset.category === category) {
                // Check if we're hovering over another item (grouping) or empty space (reordering)
                const dropTarget = e.target.closest('.review-item');
                
                if (dropTarget && dropTarget !== this.draggedItem) {
                    // This is grouping - don't move the element visually
                    return;
                } else {
                    // This is reordering - show visual feedback for both items and groups
                    const afterElement = this.getDragAfterElement(container, e.clientY);
                    const dragging = this.draggedItem;
                    
                    if (dragging && dragging instanceof Node) {
                        if (afterElement == null) {
                            container.appendChild(dragging);
                        } else {
                            container.insertBefore(dragging, afterElement);
                        }
                    }
                }
            }
        };

        this.handleDragLeave = (e) => {
            if (!container.contains(e.relatedTarget)) {
                container.classList.remove('drag-over');
            }
        };

        this.handleDrop = (e) => {
            e.preventDefault();
            container.classList.remove('drag-over');

            if (!this.draggedItem) return;

            const draggedItemId = parseInt(this.draggedItem.dataset.itemId);
            const draggedCategory = this.draggedItem.dataset.category;
            const isDraggedGroup = this.draggedItem.classList.contains('combined-group');

            // Check if we're reordering within the same column
            if (draggedCategory === category) {
                // Check if we're dropping on another item (grouping) or empty space (reordering)
                const dropTarget = e.target.closest('.review-item');
                
                if (dropTarget && dropTarget !== this.draggedItem) {
                    // This is grouping within the same column
                    const targetItemId = parseInt(dropTarget.dataset.itemId);
                    const isTargetGroup = dropTarget.classList.contains('combined-group');
                    
                    if (!isDraggedGroup && !isNaN(targetItemId) && !isTargetGroup) {
                        // Create group with these two individual items
                        this.createGroupFromItems([draggedItemId, targetItemId], category);
                    } else if (!isDraggedGroup && isTargetGroup) {
                        // Add individual item to existing group
                        const groupElement = dropTarget.closest('.review-item.combined-group');
                        if (groupElement) {
                            const groupItems = this.findItemsInGroup(groupElement);
                            if (groupItems.length > 0) {
                                this.addItemToExistingGroup(draggedItemId, groupElement);
                            }
                        }
                    } else if (isDraggedGroup && !isTargetGroup && !isNaN(targetItemId)) {
                        // Add individual item to dragged group
                        const groupElement = this.draggedItem;
                        if (groupElement) {
                            this.addItemToExistingGroup(targetItemId, groupElement);
                        }
                    }
                    // Note: Group-to-group merging is not implemented to avoid complexity
                } else {
                    // This is reordering within the same column (dropped on empty space)
                    const items = Array.from(container.querySelectorAll('.review-item'));
                    const itemIds = [];
                    const groupIds = [];

                    items.forEach(item => {
                        if (item.classList.contains('combined-group')) {
                            const groupId = item.dataset.groupId;
                            if (groupId) {
                                groupIds.push(parseInt(groupId));
                            }
                        } else {
                            const itemId = item.dataset.itemId;
                            if (itemId) {
                                itemIds.push(parseInt(itemId));
                            }
                        }
                    });

                    // Send reorder request to server
                    this.reorderItemsInColumn(category, itemIds, groupIds);
                }
                return;
            }

            // Allow dropping on any column (cross-column grouping)
            // Find the drop target (another item in the target column)
            const dropTarget = e.target.closest('.review-item');
            
            if (dropTarget && dropTarget !== this.draggedItem) {
                const targetItemId = parseInt(dropTarget.dataset.itemId);
                
                // Only proceed if targetItemId is valid (not NaN)
                if (!isNaN(targetItemId)) {
                    // Create group with these two items in the target column's category
                    this.createGroupFromItems([draggedItemId, targetItemId], category);
                } else {
                    // Dropped on a group - add the dragged item to the existing group
                    const groupElement = dropTarget.closest('.review-item.combined-group');
                    if (groupElement) {
                        // Find all items in this group
                        const groupItems = this.findItemsInGroup(groupElement);
                        if (groupItems.length > 0) {
                            // Add the dragged item to the existing group
                            this.addItemToExistingGroup(draggedItemId, groupElement);
                        }
                    }
                }
            } else if (!dropTarget) {
                // Dropped on empty space in the column - this should not create a group
                // Just return without doing anything
                return;
            }
        };

        container.addEventListener('dragstart', this.handleDragStart);
        container.addEventListener('dragend', this.handleDragEnd);
        container.addEventListener('dragover', this.handleDragOver);
        container.addEventListener('dragleave', this.handleDragLeave);
        container.addEventListener('drop', this.handleDrop);
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

RetrospectiveBoard.prototype.createGroupFromItems = async function(itemIds, category) {
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

RetrospectiveBoard.prototype.checkReviewUpdates = async function() {
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
            const newItems = data.items || [];
            const newGroups = data.groups || [];
            
            // Check if data has changed
            if (JSON.stringify(newItems) !== JSON.stringify(this.reviewItems) ||
                JSON.stringify(newGroups) !== JSON.stringify(this.reviewGroups)) {
                
                this.reviewItems = newItems;
                this.reviewGroups = newGroups;
                this.renderReviewBoard();
            }
        }
    } catch (error) {
        console.error('Error checking review updates:', error);
    }
};
