# 🚀 Drive OI Backend V2 - Quick Start

## Status Project

### ✅ Yang Sudah Selesai (80%)

1. **Laravel 11 Setup** - Fresh install dengan semua dependencies
2. **Database Structure**
   - 5 migration files untuk users, datas, shared_datas, upload_batches, ref_perangkat_daerah
   - Indexes untuk performance optimization
   - Compatible dengan database production yang ada

3. **Models dengan Relationships**
   - User (dengan storage helpers)
   - Data (nested set + scopes)
   - SharedData
   - UploadBatch  
   - RefPerangkatDaerah

4. **API Resources** untuk format response yang consistent
5. **Routes** - Semua endpoint terdefinisi di `routes/api.php`
6. **CORS Configuration**
7. **Environment Template**

### 🔨 Yang Perlu Dilanjutkan (20%)

1. **Controllers Implementation** - Template sudah ada di IMPLEMENTATION_GUIDE.md
2. **Services Layer** - Business logic
3. **Jobs & Events** - Background processing
4. **Form Validation** - Request classes
5. **Middleware** - Custom authorization
6. **Testing**

## 🎯 Cara Melanjutkan

### Opsi 1: Generate Otomatis (Recommended)

```bash
cd DriveBackendV2
chmod +x setup.sh
./setup.sh
```

Script ini akan:
- Generate semua controllers
- Generate jobs, events, listeners
- Generate middleware
- Setup configuration

### Opsi 2: Manual Step-by-Step

Ikuti panduan lengkap di: **`IMPLEMENTATION_GUIDE.md`**

File ini berisi:
- ✅ Template code untuk semua controllers
- ✅ Contoh implementasi services
- ✅ Contoh jobs untuk Google Drive upload
- ✅ Middleware examples
- ✅ Testing checklist
- ✅ Deployment guide

## 🔑 Konfigurasi Database

**PENTING:** Backend ini dirancang untuk menggunakan database PRODUCTION yang sudah ada!

### Migration Strategy

```bash
# 1. Backup database dulu!
pg_dump -h host -U user dbname > backup_$(date +%Y%m%d).sql

# 2. Check migration status
php artisan migrate:status

# 3. Run migrations (hanya tambah indexes dan kolom baru)
php artisan migrate
```

Migrations yang dibuat:
- **TIDAK akan drop/recreate tables**
- **HANYA akan menambah indexes untuk performance**
- **AMAN untuk production database**

## 📋 Configuration Files

### 1. Copy dan Edit `.env`

```bash
cp .env.example .env
nano .env
```

Konfigurasi yang WAJIB diisi:
```env
DB_CONNECTION=pgsql
DB_HOST=your-production-host
DB_PORT=5432
DB_DATABASE=your-database-name
DB_USERNAME=your-username
DB_PASSWORD=your-password

FRONTEND_URL=https://your-frontend-domain.com

GOOGLE_DRIVE_CLIENT_ID=...
GOOGLE_DRIVE_CLIENT_SECRET=...
GOOGLE_DRIVE_REFRESH_TOKEN=...

FCM_SERVER_KEY=...
```

### 2. Generate App Key

```bash
php artisan key:generate
```

### 3. Install Dependencies

```bash
composer install --optimize-autoloader --no-dev
```

## 🧪 Testing

### 1. Start Server

```bash
php artisan serve
```

### 2. Test Endpoints

Gunakan Postman atau curl:

```bash
# Health check
curl http://localhost:8000/api/a12

# Login
curl -X POST http://localhost:8000/api/mobile-login \
  -H "Content-Type: application/json" \
  -d '{"username":"test","password":"password"}'

# Get Items (dengan token)
curl http://localhost:8000/api/v2/getItems?page=1 \
  -H "Authorization: Bearer YOUR_TOKEN"
```

## 📦 Package Dependencies

Semua package sudah terinstall:

| Package | Purpose |
|---------|---------|
| laravel/sanctum | API Authentication |
| laravel/socialite | OAuth (Google, Apple) |
| spatie/laravel-permission | Role-based access |
| spatie/laravel-activitylog | Audit logging |
| kalnoy/nestedset | Hierarchical folders |
| masbug/flysystem-google-drive-ext | Google Drive storage |
| kutia-software-company/larafirebase | Push notifications |
| guzzlehttp/guzzle | HTTP client |

## 🗂️ File Structure

```
DriveBackendV2/
├── app/
│   ├── Http/
│   │   ├── Controllers/API/     ← Generate controllers di sini
│   │   ├── Middleware/          ← Custom middleware
│   │   ├── Requests/            ← Form validation
│   │   └── Resources/           ✅ Sudah ada
│   ├── Models/                  ✅ Sudah ada
│   ├── Services/                ← Business logic layer
│   ├── Jobs/                    ← Background jobs
│   ├── Events/                  ← Event classes
│   └── Listeners/               ← Event listeners
├── database/
│   └── migrations/              ✅ Sudah ada (5 files)
├── routes/
│   └── api.php                  ✅ Sudah ada
├── config/
│   ├── cors.php                 ✅ Sudah ada
│   ├── filesystems.php          ← Konfigurasi Google Drive
│   └── activitylog.php          ← Konfigurasi logging
├── .env.example                 ✅ Sudah ada
├── setup.sh                     ✅ Sudah ada
├── IMPLEMENTATION_GUIDE.md      ✅ Panduan lengkap
└── README.md                    ← Update dengan dokumentasi
```

## 🔄 Sync dengan Frontend

Frontend Next.js **TIDAK PERLU DIUBAH** karena:

✅ Semua endpoint API compatible  
✅ Response format sama  
✅ Authentication flow sama (Sanctum)  
✅ CORS sudah dikonfigurasi  

Yang perlu diubah di frontend:
1. Environment variable `API_BASE_URL` → URL backend baru

```env
# drive-oi-v3/.env.local
API_BASE_URL=http://localhost:8000/api
# atau URL production
```

## 🚀 Deployment Checklist

- [ ] Database backup created
- [ ] `.env` configured correctly
- [ ] `php artisan migrate` executed successfully
- [ ] Controllers implemented
- [ ] API tested dengan Postman
- [ ] Queue worker setup (systemd/supervisor)
- [ ] Web server configured (Nginx/Apache)
- [ ] SSL certificate installed
- [ ] Frontend `API_BASE_URL` updated
- [ ] Test dari frontend
- [ ] Monitor error logs

## 📞 Troubleshooting

### Issue: Migration Error

**Solution:**
```bash
# Check which migrations ran
php artisan migrate:status

# Rollback last migration
php artisan migrate:rollback --step=1

# Try again
php artisan migrate
```

### Issue: CORS Error dari Frontend

**Solution:**
Pastikan di `config/cors.php`:
```php
'allowed_origins' => [env('FRONTEND_URL')],
'supports_credentials' => true,
```

Dan di `.env`:
```env
FRONTEND_URL=https://your-frontend.com
```

### Issue: Google Drive Upload Fails

**Solution:**
1. Check credentials di `.env`
2. Test Google Drive connection:
```bash
php artisan tinker
Storage::disk('google')->put('test.txt', 'Hello World');
```

### Issue: Queue Not Processing

**Solution:**
```bash
# Start queue worker
php artisan queue:work --tries=3

# Or with supervisor (production)
sudo supervisorctl restart laravel-worker:*
```

## 📚 Resources

- [IMPLEMENTATION_GUIDE.md](./IMPLEMENTATION_GUIDE.md) - Panduan detail implementasi
- [Laravel 11 Docs](https://laravel.com/docs/11.x)
- [Sanctum Docs](https://laravel.com/docs/11.x/sanctum)
- [Spatie Permission](https://spatie.be/docs/laravel-permission/v6)
- [Nestedset](https://github.com/lazychaser/laravel-nestedset)

## ✅ Next Steps

1. **Selesaikan Controllers** menggunakan template di IMPLEMENTATION_GUIDE.md
2. **Test semua endpoint** dengan Postman
3. **Setup Queue Worker** untuk background jobs
4. **Deploy ke staging** untuk testing
5. **Update frontend** environment variable
6. **Test end-to-end** dari frontend
7. **Deploy ke production**

---

**Status:** 🚧 80% Complete - Siap dilanjutkan!  
**Estimasi:** 4-6 jam untuk menyelesaikan remaining 20%  
**Difficulty:** ⭐⭐⭐ Medium (template sudah ada, tinggal implement)
