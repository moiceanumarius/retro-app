# Strategie de Backup și Prevenire Pierdere Date

## 🚨 Problema Identificată
- Data: 8 octombrie 2025, 13:18
- Cauză: MySQL nu s-a închis corect, datele mutate în RECOVER_YOUR_DATA
- Impact: Pierderea datelor între 5 octombrie și 8 octombrie

## 🛡️ Soluții de Prevenire

### 1. **Backup Automat Zilnic**

Adaugă în `Makefile`:
```makefile
backup-db:
	@echo "Creating database backup..."
	@mkdir -p backups
	@docker exec retro_app_mysql_dev mysqldump -uroot -proot retro_app > backups/retro_app_$(shell date +%Y%m%d_%H%M%S).sql
	@echo "Backup created in backups/ directory"
	@ls -lh backups/ | tail -1

restore-db:
	@echo "Available backups:"
	@ls -1 backups/*.sql
	@read -p "Enter backup filename: " backup; \
	docker exec -i retro_app_mysql_dev mysql -uroot -proot retro_app < backups/$$backup
```

### 2. **Script Backup Automat (Cron)**

Creează `scripts/auto-backup.sh`:
```bash
#!/bin/bash
BACKUP_DIR="/Users/marius/Desktop/retro-app/backups"
DATE=$(date +%Y%m%d_%H%M%S)
CONTAINER="retro_app_mysql_dev"

# Crează directorul dacă nu există
mkdir -p "$BACKUP_DIR"

# Backup
docker exec $CONTAINER mysqldump -uroot -proot --single-transaction retro_app > "$BACKUP_DIR/retro_app_$DATE.sql"

# Păstrează doar ultimele 7 zile de backup-uri
find "$BACKUP_DIR" -name "retro_app_*.sql" -mtime +7 -delete

echo "Backup completed: retro_app_$DATE.sql"
```

Rulează zilnic cu cron:
```bash
0 2 * * * /Users/marius/Desktop/retro-app/scripts/auto-backup.sh
```

### 3. **Safe Shutdown Configuration**

Modifică `docker-compose.dev.yml` pentru a adăuga shutdown corect:
```yaml
mysql:
  image: mysql:8.0
  container_name: retro_app_mysql_dev
  stop_grace_period: 30s  # Dă timp MySQL să se închidă corect
  stop_signal: SIGTERM     # Signal corect pentru shutdown
```

### 4. **Volume Snapshot (Opțional)**

Pentru siguranță maximă, folosește Docker volume backup:
```bash
# Backup volume
docker run --rm -v retro-app_mysql_dev_data:/data -v $(pwd)/backups:/backup alpine tar czf /backup/mysql_volume_$(date +%Y%m%d).tar.gz -C /data .

# Restore volume
docker run --rm -v retro-app_mysql_dev_data:/data -v $(pwd)/backups:/backup alpine tar xzf /backup/mysql_volume_YYYYMMDD.tar.gz -C /data
```

### 5. **Monitorizare Status MySQL**

Adaugă în `Makefile`:
```makefile
check-mysql-status:
	@docker exec retro_app_mysql_dev mysql -uroot -proot -e "SHOW ENGINE INNODB STATUS\G" | grep -A 20 "LATEST DETECTED DEADLOCK"
	@docker exec retro_app_mysql_dev mysql -uroot -proot -e "SHOW VARIABLES LIKE 'innodb_flush_log_at_trx_commit';"
```

### 6. **Best Practices**

✅ **DO:**
- Întotdeauna folosește `docker-compose down` (nu `docker kill`)
- Fă backup înainte de restart
- Oprește corect computerul (nu forțat)
- Verifică disk space înainte de operații majore

❌ **DON'T:**
- Nu folosi `docker kill` pe containerul MySQL
- Nu reporni forțat computerul când Docker rulează
- Nu șterge volume-uri fără backup
- Nu folosi `docker-compose down -v` (șterge volume-urile!)

## 📋 Checklist Pre-Restart

Înainte de orice restart de containere:
- [ ] Verifică că MySQL nu procesează query-uri importante
- [ ] (Opțional) Fă un backup rapid: `make backup-db`
- [ ] Folosește `docker-compose down` (nu `down -v`)
- [ ] Verifică că volumele există: `docker volume ls | grep mysql`

## 🔄 Recovery în caz de problemă

Dacă se întâmplă din nou:
1. NU șterge volumele Docker
2. Verifică în `/var/lib/mysql/` dacă există RECOVER_YOUR_DATA
3. Încearcă să recuperezi datele din binlog-uri
4. Contactează un DBA dacă datele sunt critice

## 📊 Monitoring

Adaugă logging pentru MySQL:
```yaml
mysql:
  logging:
    driver: "json-file"
    options:
      max-size: "10m"
      max-file: "3"
```
