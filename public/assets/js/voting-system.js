// ============================================
// VOTING SYSTEM for RetrospectiveBoard
// ============================================

RetrospectiveBoard.prototype.initVoting = async function() {
    console.log('Initializing voting system...');
    this.votingActive = true;
    
    // Load existing votes from server
    await this.loadUserVotes();
    
    // Show all voting controls
    const votingControls = document.querySelectorAll('.voting-controls');
    votingControls.forEach(control => {
        control.style.display = 'flex';
    });
    
    // Add event listeners to vote buttons
    this.attachVotingListeners();
    
    // Update button states based on loaded votes
    this.updateVoteButtons();
};

RetrospectiveBoard.prototype.loadUserVotes = async function() {
    try {
        const response = await fetch(`/retrospectives/${this.retrospectiveId}/votes`, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            credentials: 'include'
        });

        if (response.ok) {
            const data = await response.json();
            console.log('Loaded user votes:', data);
            
            // Reset vote tracking
            this.userVotes = {};
            this.totalVotes = 0;
            
            // Reset all vote badges to 0
            document.querySelectorAll('.votes[data-item-id], .votes[data-group-id]').forEach(badge => {
                badge.textContent = '0 votes';
            });
            
            // Restore votes from server
            data.votes.forEach(voteData => {
                const key = `item-${voteData.itemId}`;
                this.userVotes[key] = voteData.voteCount;
                this.totalVotes += voteData.voteCount;
                
                // Update vote input
                const input = document.querySelector(`.vote-input[data-item-id="${voteData.itemId}"]`);
                if (input) {
                    input.value = voteData.voteCount;
                }
                
                // Update vote badge (visible all the time)
                const voteBadge = document.querySelector(`.votes[data-item-id="${voteData.itemId}"]`);
                if (voteBadge) {
                    const voteText = voteData.voteCount === 1 ? 'vote' : 'votes';
                    voteBadge.textContent = `${voteData.voteCount} ${voteText}`;
                }
            });
            
            console.log(`Restored ${this.totalVotes} total votes`);
        } else {
            console.error('Failed to load votes:', response.status);
        }
    } catch (error) {
        console.error('Error loading votes:', error);
    }
};

RetrospectiveBoard.prototype.attachVotingListeners = function() {
    // Remove existing listeners to prevent duplicates
    document.querySelectorAll('.vote-btn').forEach(btn => {
        const newBtn = btn.cloneNode(true);
        btn.parentNode.replaceChild(newBtn, btn);
    });
    
    // Add new listeners
    document.querySelectorAll('.vote-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation(); // Prevent drag and drop interference
            const action = btn.dataset.action;
            const itemId = btn.dataset.itemId;
            const groupId = btn.dataset.groupId;
            
            if (action === 'increase') {
                this.increaseVote(itemId, groupId);
            } else if (action === 'decrease') {
                this.decreaseVote(itemId, groupId);
            }
        });
    });
};

RetrospectiveBoard.prototype.increaseVote = function(itemId, groupId) {
    const targetId = itemId || groupId;
    const targetType = itemId ? 'item' : 'group';
    const key = `${targetType}-${targetId}`;
    
    const currentVotes = this.userVotes[key] || 0;
    
    // Check if max votes per item reached
    if (currentVotes >= this.maxVotesPerItem) {
        alert(`You can only vote ${this.maxVotesPerItem} times for this ${targetType}!`);
        return;
    }
    
    // Check if total votes limit reached
    if (this.totalVotes >= this.maxTotalVotes) {
        alert('You don\'t have any more votes!');
        return;
    }
    
    // Increment vote
    this.userVotes[key] = currentVotes + 1;
    this.totalVotes++;
    
    // Update UI
    this.updateVoteDisplay(itemId, groupId);
    this.updateVoteButtons();
    
    // Save to backend
    this.saveVote(targetId, targetType, this.userVotes[key]);
};

RetrospectiveBoard.prototype.decreaseVote = function(itemId, groupId) {
    const targetId = itemId || groupId;
    const targetType = itemId ? 'item' : 'group';
    const key = `${targetType}-${targetId}`;
    
    const currentVotes = this.userVotes[key] || 0;
    
    if (currentVotes === 0) {
        return; // Can't decrease below 0
    }
    
    // Decrement vote
    this.userVotes[key] = currentVotes - 1;
    this.totalVotes--;
    
    // Update UI
    this.updateVoteDisplay(itemId, groupId);
    this.updateVoteButtons();
    
    // Save to backend
    this.saveVote(targetId, targetType, this.userVotes[key]);
};

RetrospectiveBoard.prototype.updateVoteDisplay = function(itemId, groupId) {
    const targetId = itemId || groupId;
    const targetType = itemId ? 'item' : 'group';
    const key = `${targetType}-${targetId}`;
    const voteCount = this.userVotes[key] || 0;
    
    // Update vote input (in voting controls)
    const input = itemId 
        ? document.querySelector(`.vote-input[data-item-id="${itemId}"]`)
        : document.querySelector(`.vote-input[data-group-id="${groupId}"]`);
    
    if (input) {
        input.value = voteCount;
    }
    
    // Update vote badge (visible all the time)
    const voteBadge = itemId
        ? document.querySelector(`.votes[data-item-id="${itemId}"]`)
        : document.querySelector(`.votes[data-group-id="${groupId}"]`);
    
    if (voteBadge) {
        const voteText = voteCount === 1 ? 'vote' : 'votes';
        voteBadge.textContent = `${voteCount} ${voteText}`;
    }
};

RetrospectiveBoard.prototype.updateVoteButtons = function() {
    // Update all increase buttons based on total votes
    document.querySelectorAll('.vote-increase').forEach(btn => {
        const itemId = btn.dataset.itemId;
        const groupId = btn.dataset.groupId;
        const targetId = itemId || groupId;
        const targetType = itemId ? 'item' : 'group';
        const key = `${targetType}-${targetId}`;
        
        const currentVotes = this.userVotes[key] || 0;
        
        // Disable if max per item reached or total votes reached
        if (currentVotes >= this.maxVotesPerItem || this.totalVotes >= this.maxTotalVotes) {
            btn.disabled = true;
        } else {
            btn.disabled = false;
        }
    });
    
    // Update all decrease buttons based on current votes
    document.querySelectorAll('.vote-decrease').forEach(btn => {
        const itemId = btn.dataset.itemId;
        const groupId = btn.dataset.groupId;
        const targetId = itemId || groupId;
        const targetType = itemId ? 'item' : 'group';
        const key = `${targetType}-${targetId}`;
        
        const currentVotes = this.userVotes[key] || 0;
        
        // Disable if no votes
        if (currentVotes === 0) {
            btn.disabled = true;
        } else {
            btn.disabled = false;
        }
    });
};

RetrospectiveBoard.prototype.saveVote = async function(targetId, targetType, voteCount) {
    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        
        const response = await fetch(`/retrospectives/${this.retrospectiveId}/vote`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'X-CSRF-Token': csrfToken || ''
            },
            credentials: 'include',
            body: JSON.stringify({
                targetId: targetId,
                targetType: targetType,
                voteCount: voteCount,
                _token: csrfToken
            })
        });

        if (response.ok) {
            console.log(`Vote saved: ${targetType} ${targetId} = ${voteCount}`);
        } else {
            console.error('Failed to save vote:', response.status);
        }
    } catch (error) {
        console.error('Error saving vote:', error);
    }
};

RetrospectiveBoard.prototype.stopVoting = function() {
    console.log('Stopping voting system...');
    this.votingActive = false;
    
    // Hide all voting controls
    const votingControls = document.querySelectorAll('.voting-controls');
    votingControls.forEach(control => {
        control.style.display = 'none';
    });
};

