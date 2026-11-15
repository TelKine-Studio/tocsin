# Tocsin — server

Laravel 13 application providing the web interface, the REST API and the realtime (WebSocket) server of Tocsin.

## Architecture

| Area | Location |
| --- | --- |
| Settings stored in the database (no `.env` needed in production) | `app/Support/Settings.php` |
| First-run setup | `app/Http/Controllers/SetupController.php`, `app/Http/Middleware/EnsureApplicationIsInstalled.php` |
| Topics & permissions | `app/Models/Topic.php`, `app/Policies/TopicPolicy.php` |
| Publishing (validation, storage, broadcast) | `app/Actions/PublishMessage.php`, `app/Events/MessagePublished.php` |
| REST API | `routes/api.php`, `app/Http/Controllers/Api/V1` |
| Private channel authorization | `routes/channels.php` |
| Web interface (server-rendered Blade, no SPA) | `resources/views` |
| Docker image | `Dockerfile`, `docker/` |

## Local development

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate && php artisan tocsin:bootstrap

composer run dev           # http://localhost:8000 + Vite + queue worker
php artisan reverb:start   # WebSocket server on :8080
```

Open <http://localhost:8000> and complete the setup.

## Tests and code style

```bash
php artisan test
./vendor/bin/pint
```
