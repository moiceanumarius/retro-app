# Retro App - Aplicație pentru Retrospective Agile

O aplicație web pentru gestionarea retrospectivelor sprinturilor agile, construită cu Symfony 7.3 LTS și Docker.

## Tehnologii

- **Backend**: Symfony 7.3 LTS (PHP 8.3)
- **Baza de date**: MySQL 8.0
- **Containerizare**: Docker & Docker Compose
- **Web Server**: Nginx
- **ORM**: Doctrine
- **Templating**: Twig

## Structura Proiectului

```
retro-app/
├── docker/                 # Configurații Docker
│   ├── nginx/             # Configurație Nginx
│   ├── php/               # Dockerfile și php.ini pentru PHP
│   └── mysql/             # Scripturi de inițializare MySQL
├── src/                   # Cod sursă Symfony
├── public/                # Fișiere publice
├── config/                # Configurări Symfony
├── docker-compose.yml     # Configurație Docker Compose
└── README.md
```

## Instalare și Configurare

### Cerințe

- Docker
- Docker Compose
- Make (opțional, pentru comenzi rapide)

### Pași de instalare

1. **Clonează repository-ul**:
   ```bash
   git clone <repository-url>
   cd retro-app
   ```

2. **Pornește mediu de dezvoltare**:
   ```bash
   make dev
   # sau
   docker-compose -f docker-compose.yml -f docker-compose.dev.yml up -d
   ```

3. **Pentru producție, configurează fișierul de mediu**:
   ```bash
   cp .env.prod.example .env.prod
   # Editează .env.prod cu valorile corecte
   ```

4. **Pornește mediu de producție**:
   ```bash
   make prod
   # sau
   docker-compose -f docker-compose.yml -f docker-compose.prod.yml up -d
   ```

## Accesare Aplicație

### Development
- **Aplicația web**: http://localhost:8080
- **phpMyAdmin**: http://localhost:8081
- **MailHog**: http://localhost:8025
- **MySQL**: localhost:3306

### Production
- **Aplicația web**: https://localhost (port 80/443)
- **Prometheus**: http://localhost:9090
- **MySQL**: localhost:3306

## Credențiale MySQL

- **Utilizator**: retro_user
- **Parolă**: retro_password
- **Baza de date**: retro_app

## Comenzi Utile

### Make Commands (Recomandat)
```bash
# Development
make dev          # Pornește mediu de dezvoltare
make dev-logs     # Vezi logurile
make dev-shell    # Intră în container

# Production
make prod         # Pornește mediu de producție
make prod-logs    # Vezi logurile
make prod-shell   # Intră în container

# General
make stop         # Oprește toate containerele
make clean        # Curăță tot
make console      # Rulează comenzi Symfony
```

### Docker Compose
```bash
# Development
docker-compose -f docker-compose.yml -f docker-compose.dev.yml up -d

# Production
docker-compose -f docker-compose.yml -f docker-compose.prod.yml up -d

# Oprește serviciile
docker-compose down

# Vezi logurile
docker-compose logs -f
```

### Symfony
```bash
# Rulează migrațiile
make migrate
# sau
docker-compose exec app php bin/console doctrine:migrations:migrate

# Creează o nouă entitate
make console make:entity

# Creează un controller
make console make:controller

# Curăță cache-ul
make cache-clear
```

## Funcționalități Planificate

- [ ] Gestionarea echipelor
- [ ] Gestionarea sprinturilor
- [ ] Crearea retrospectivelor
- [ ] Adăugarea elementelor de retrospectivă (ce a mers bine, ce să îmbunătățim, acțiuni)
- [ ] Sistem de votare pentru elementele de retrospectivă
- [ ] Exportul retrospectivelor
- [ ] Autentificare utilizatori

## Dezvoltare

Pentru dezvoltare, folosește:

```bash
# Pornește serviciile în modul development
docker-compose up -d

# Monitorizează logurile
docker-compose logs -f app
```

## Contribuții

1. Fork repository-ul
2. Creează o ramură pentru feature (`git checkout -b feature/nume-feature`)
3. Commit modificările (`git commit -am 'Adaugă feature'`)
4. Push la ramură (`git push origin feature/nume-feature`)
5. Creează un Pull Request
