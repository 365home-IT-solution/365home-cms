# Deployment Guide - Docker CI/CD Pipeline

## Tổng quan luồng deploy

```
1. Developer push code → production branch
                ↓
2. GitHub Actions tự động:
   - Check code validity
   - Build Docker image
   - Push to GitHub Container Registry (GHCR)
                ↓
3. SSH vào VPS:
   - Login GHCR
   - Pull image
   - docker compose up -d
   - Migrations + Cache tự chạy
                ↓
4. App live ✅
```

---

## Setup GitHub Secrets

Bạn cần thêm những secrets này vào **Settings > Secrets and variables > Actions**:

### VPS Credentials

| Secret Name | Mô tả | Ví dụ |
|---|---|---|
| `VPS_HOST` | IP hoặc domain VPS | `1.2.3.4` hoặc `vps.example.com` |
| `VPS_USER` | SSH user | `root` hoặc `dev` |
| `VPS_SSH_KEY` | SSH private key | Content của `~/.ssh/id_rsa` |
| `VPS_PORT` | SSH port | `22` (mặc định) |
| `GH_PAT` | GitHub Personal Access Token | [Tạo tại](https://github.com/settings/tokens) |

### Reverb Config (nếu dùng Reverb)

| Secret Name | Mô tả | Từ |
|---|---|---|
| `VITE_REVERB_APP_KEY` | Reverb app key | `.env` - `REVERB_APP_KEY` |
| `VITE_REVERB_HOST` | Reverb host | `.env` - `VITE_REVERB_HOST` |
| `VITE_REVERB_PORT` | Reverb port | `.env` - `VITE_REVERB_PORT` |
| `VITE_REVERB_SCHEME` | ws hoặc wss | `.env` - `VITE_REVERB_SCHEME` |
| `VITE_REVERB_PATH` | Reverb path | `.env` - `VITE_REVERB_PATH` |

### Cách tạo GitHub Personal Access Token

1. Vào https://github.com/settings/tokens
2. Click **"Generate new token"** → **"Generate new token (classic)"**
3. Name: `365home-deploy`
4. Expiration: `90 days` (hoặc tùy thích)
5. Scopes:
   - ✅ `repo` (full control)
   - ✅ `read:packages` (download packages)
   - ✅ `write:packages` (upload packages)
6. Click **"Generate token"** và copy
7. Paste vào GitHub Secret `GH_PAT`

### Cách lấy SSH Private Key

Trên máy của bạn:

```bash
# Nếu chưa có SSH key, tạo mới:
ssh-keygen -t rsa -b 4096 -f ~/.ssh/id_rsa

# Copy nội dung private key:
cat ~/.ssh/id_rsa
```

Paste toàn bộ nội dung (bao gồm `-----BEGIN RSA PRIVATE KEY-----` và `-----END RSA PRIVATE KEY-----`) vào secret `VPS_SSH_KEY`.

Rồi copy public key lên VPS:

```bash
# Trên máy local
ssh-copy-id -i ~/.ssh/id_rsa.pub user@vps_ip

# Hoặc manual:
# 1. Copy content của ~/.ssh/id_rsa.pub
# 2. SSH vào VPS: ssh user@vps_ip
# 3. Append vào ~/.ssh/authorized_keys: echo "paste_key_here" >> ~/.ssh/authorized_keys
```

---

## Setup VPS

### 1. Chuẩn bị thư mục project

```bash
# SSH vào VPS
ssh user@vps_ip

# Tạo thư mục
mkdir -p /home/dev/www/365home.vn
cd /home/dev/www/365home.vn

# Copy .env từ local (đảm bảo .env có DB_HOST, REVERB_*, v.v. đúng)
# Hoặc create .env theo .env.example

# Tạo volume storage nếu chưa có
mkdir -p storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

### 2. Cấu hình Docker

```bash
# Đảm bảo Docker & Docker Compose đã cài
docker --version
docker compose version

# Login GHCR lần đầu (để test)
echo "YOUR_GITHUB_TOKEN" | docker login ghcr.io -u YOUR_GITHUB_USERNAME --password-stdin

# Verify có thể pull image
docker pull ghcr.io/365home-it-solution/365home-cms:latest
```

### 3. MariaDB Network

```bash
# Đảm bảo network mariadb_default tồn tại
docker network ls | grep mariadb_default

# Nếu không có, tạo:
docker network create mariadb_default
```

### 4. PM2 Setup (cho websocket)

```bash
# Install PM2 globally
npm install -g pm2

# Verify
pm2 --version

# Startup script để PM2 auto-start trên reboot
pm2 startup
pm2 save
```

---

## Deploy Workflow Chi tiết

### 1. Local: Push code

```bash
git add .
git commit -m "feat: something"
git push origin production  # Trigger workflow
```

### 2. GitHub Actions: Check + Build

**Job 1: check** (3 phút)
- ✅ Validate composer.json
- ✅ Check PHP syntax
- ✅ Test artisan boots

**Job 2: build** (10-15 phút)
- ✅ Build Docker image
- ✅ Push to ghcr.io

**Job 3: deploy** (5 phút)
- ✅ SSH vào VPS
- ✅ Pull image từ GHCR
- ✅ `docker compose -f compose.prod.yaml up -d`
- ✅ Migrations & cache tự chạy (entrypoint.sh)

### 3. Monitoring

Xem logs deploy:

```bash
# Trên VPS
cd /home/dev/www/365home.vn

# Xem container logs
docker compose logs -f app

# Xem websocket
pm2 logs

# Xem status
docker compose ps
pm2 list
```

---

## Troubleshooting

### Image không pull được

```bash
# Check login
docker login ghcr.io

# Verify token có quyền read:packages
# (xem lại GitHub PAT scopes)

# Try pull manually
docker pull ghcr.io/365home-it-solution/365home-cms:latest
```

### Migrations fail

```bash
# Xem logs app container
docker compose logs app

# Hoặc run thủ công
docker compose exec app php artisan migrate --force
```

### Storage permissions error

```bash
# Fix trên VPS
cd /home/dev/www/365home.vn
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

### Reverb không connect

```bash
# Check Reverb logs
pm2 logs websocket

# Hoặc container reverb
docker compose logs reverb

# Verify REVERB_* vars trong .env
cat .env | grep REVERB
```

---

## Rollback (nếu deploy bị lỗi)

```bash
# Trên VPS
cd /home/dev/www/365home.vn

# Quay về image trước đó
DOCKER_IMAGE=ghcr.io/365home-it-solution/365home-cms:previous_sha docker compose -f compose.prod.yaml up -d

# Hoặc dùng git để quay lại commit trước
git reset --hard previous_commit_hash
git push --force origin production
```

---

## Monitoring & Logging

### GitHub Actions

Xem logs workflow:
1. Repo → **Actions**
2. Chọn workflow run
3. Click từng job để xem logs

### VPS Logs

```bash
# App logs
docker compose logs -f app --tail 50

# Reverb/Websocket
pm2 logs websocket

# System
journalctl -u docker -f
```

---

## Lần sau deploy

```bash
git add .
git commit -m "fix: bug"
git push origin production
# Tự động deploy! ✅
```

Done! 🚀
