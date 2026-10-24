# CivicShield Local Deployment Guide

## Requirements

- Windows with XAMPP (Apache, PHP CLI and MySQL/MariaDB) or an equivalent PHP/MySQL environment.
- PHP 8.1+ is recommended. Verify required extensions such as PDO MySQL and fileinfo; GD may be used by image-processing paths.
- A modern browser.
- Git is optional when the project folder is already available.

## 1. Configure environment

From the project root in PowerShell:

```powershell
Copy-Item .env.example .env
```

Edit `.env`. For a project served as `http://localhost/OEP`, configure:

```ini
APP_ENV=development
APP_DEBUG=true
APP_URL=http://localhost/OEP
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=civicshield_db
DB_USER=root
DB_PASS=
```

The sample values are for local development. Use a strong, unique `CSRF_SECRET_KEY` for shared deployments and disable debug output outside local development.

## 2. Initialize the database

Start Apache and MySQL in the XAMPP Control Panel. From the project root:

```powershell
& "C:\xampp\mysql\bin\mysql.exe" -u root -e "source database/schema.sql"
& "C:\xampp\mysql\bin\mysql.exe" -u root -e "source database/seed.sql"
```

If your database account has a password, add the appropriate client option. Use migrations only when upgrading an older schema; review the migration order for that database version.

## 3. Serve the project through Apache

Place the project under `C:\xampp\htdocs\OEP` or create a directory junction to the project folder. Set `APP_URL=http://localhost/OEP` and open `http://localhost/OEP/`.

Alternatively, from the project root, run PHP's development server:

```powershell
php -S localhost:8000
```

For the built-in server, set `APP_URL=http://localhost:8000` and open `http://localhost:8000/`. This server is for local development, not public production hosting.

## 4. Verify

Open the configured application URL and check the health endpoint:

```powershell
Invoke-WebRequest "http://localhost/OEP/api/v1/health.php"
```

The public health response is intentionally minimal.

## 5. Troubleshooting

| Symptom | Check |
|---|---|
| Database connection error | Verify MySQL is running and DB_HOST/PORT/NAME/USER/PASS match the local database |
| Redirect uses wrong path | Verify `APP_URL` matches the URL used to access the app |
| PHP source displays/downloads | Use an actual PHP server; do not open PHP files directly from disk |
| Port already in use | Stop the other process or choose another port and update `APP_URL` |
| Upload fails | Check size/type constraints, writable upload directories and required extensions |

Never commit `.env`, credentials, production database dumps, or real citizen data.
