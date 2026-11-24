# Deploy files

This folder holds the files that run Snapsbrew on a server. The Docker image uses `docker/`. The other folders are examples for a server. Copy an example, then replace the example values with your values.

| File | What it does |
|---|---|
| `docker/entrypoint.d/60-snapsbrew-setup.sh` | The Docker image runs this script at start. It creates the Passport keys and prints the setup URL. |
| `browserless/docker-compose.yml` | Runs Browserless for an install without Docker or for local development. Read `browserless/README.md`. |
| `nginx/snapsbrew-docker.conf` | NGINX terminates TLS in front of the Docker stack (`127.0.0.1:8080`). |
| `nginx/snapsbrew-php-fpm.conf` | NGINX serves the app with PHP-FPM, for an install without Docker. |
| `caddy/Caddyfile` | Caddy terminates TLS in front of the Docker stack. |
| `caddy/Caddyfile.php-fpm` | Caddy serves the app with PHP-FPM, for an install without Docker. |
| `systemd/snapsbrew-horizon.service` | systemd keeps Horizon, the queue worker, running. |
| `systemd/snapsbrew-scheduler.service` | systemd keeps the scheduler (`php artisan schedule:work`) running. |
| `supervisor/snapsbrew.conf` | Supervisor keeps Horizon and the scheduler running. Use it instead of the systemd units. |
| `php/snapsbrew.ini` | Sets the PHP upload and memory limits for PHP-FPM. |

## The example values

The examples use these values. Replace each one:

| Value | What it is |
|---|---|
| `snapsbrew.example` | The host of `APP_URL`. |
| `/var/www/snapsbrew` | The folder of the app. |
| `www-data` | The user that runs PHP-FPM, Horizon and the scheduler. |
| `/usr/bin/php` | The PHP CLI. |
| `/run/php/php8.4-fpm.sock` | The socket of PHP-FPM. |
| `/etc/letsencrypt/live/snapsbrew.example/` | The certificate folder for NGINX. Caddy gets its own certificate. |

## The background processes

An install without Docker needs two processes next to the web server:

- Horizon (`php artisan horizon`) runs the queues `renders`, `imports`, `mail` and `default`.
- The scheduler runs the commands of `app/Console/Kernel.php` each minute.

Use systemd or Supervisor for both. Do not run the scheduler unit and the cron entry `schedule:run` together.

After each deploy, run `php artisan horizon:terminate`. Horizon stops after the current jobs, and the process manager starts it again with the new code. The stop timeout is 130 seconds, because a creative package item can run for 120 seconds.
