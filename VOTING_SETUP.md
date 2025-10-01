# Voting System Setup

## Database Migration

After pulling these changes, you need to run the database migration to create the `votes` table:

```bash
# Start Docker containers
docker-compose up -d

# Run the migration
docker-compose exec php bin/console doctrine:migrations:migrate --no-interaction

# Or if using Make
make migration-run
```

## How It Works

1. **Vote Entity**: Each vote is stored individually per user and item
2. **Persistence**: Votes are saved to database and restored on page refresh
3. **Limits**: Max 2 votes per item, max 10 votes total per user
4. **Real-time**: Votes are broadcast via WebSocket to all participants

## Testing

1. Navigate to Discussion phase in a retrospective
2. Start the timer
3. Vote on some items (use + and - buttons)
4. Refresh the page
5. Votes should be restored automatically

## API Endpoints

- `GET /retrospectives/{id}/votes` - Get user's votes for a retrospective
- `POST /retrospectives/{id}/vote` - Save/update a vote

