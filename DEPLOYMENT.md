# Despliegue en el VPS (Hostinger)

Dominios confirmados:

| Pieza | URL |
|---|---|
| Front (SPA) | https://cartera.casasylotes.com.co |
| API | https://api.casasylotes.com.co |

HTTPS ya está en el servidor. Esta guía asume Linux, nginx y PostgreSQL vacío, listo para `migrate` e importar San Miguel.

Trabaja siempre sobre clones de Git. Nada de push desde aquí: el procedimiento es para el VPS.

---

## 1. Requisitos del servidor

- PHP **8.3 o superior** (el `composer.json` exige `^8.3`)
- Extensiones PHP:
  - **`bcmath`** — **sin esta el motor financiero no funciona** (`bcadd`, `bcmul`, PMT, mora, recaudo). Comprueba con `php -m | grep bcmath`.
  - `gd` — PDFs de amortización (DomPDF)
  - `zip` — lectura del Excel de San Miguel (PhpSpreadsheet)
  - `pdo_pgsql` — PostgreSQL
- Composer 2
- Node.js LTS (solo para construir el front; en el VPS puedes construir ahí o subir el `dist/` ya generado)
- nginx + php-fpm
- PostgreSQL

```bash
php -v
php -m | grep -E 'bcmath|gd|zip|pdo_pgsql'
```

Si `bcmath` no aparece, **no sigas**. Los importes y las cuotas quedarían mal o el proceso reventaría.

---

## 2. Advertencia crítica: cómo llega el código al servidor

**El código debe llegar al servidor por `git clone`, NUNCA por rsync ni copiando carpetas desde macOS.**

El repositorio tiene dos directorios que solo se distinguen por mayúsculas:

- `app/Imports` — clases PHP del importador (`App\Imports\SanMiguel\…`)
- `app/imports` — el Excel y los PDF (`SAN_MIGUEL_AMORTIZACION_Y_PAGOS.xlsx`, etc.)

En macOS el disco no distingue mayúsculas, así que copiar desde el Mac aplasta los dos en uno solo y el importador de San Miguel deja de funcionar en Linux.

Tras el clone, verifica que Linux ve **ambas**:

```bash
ls app/Imports/SanMiguel
ls app/imports/*.xlsx
```

Si una de las dos falta, el clone no está bien. No improvises con `cp` desde un Mac.

---

## 3. Secuencia de despliegue (API)

Rutas de ejemplo: `/var/www/cartera-api` y `/var/www/cartera-front`. Ajústalas a las del VPS.

```bash
cd /var/www
git clone <url-del-repo-cartera-api> cartera-api
cd cartera-api

composer install --no-dev --optimize-autoloader

cp .env.production.example .env
# Edita .env: APP_KEY (ver abajo), DB_PASSWORD, y confirma APP_URL / CORS.
php artisan key:generate

# Permisos
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache
```

En `.env` tienen que quedar **antes** de cachear config:

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL=https://api.casasylotes.com.co`
- `APP_TIMEZONE=America/Bogota`
- `DB_CONNECTION=pgsql` y credenciales reales
- `CORS_ALLOWED_ORIGINS=https://cartera.casasylotes.com.co`
- `LOG_LEVEL=error`
- `SANCTUM_TOKEN_INACTIVITY=5` (o el valor que quieras; ahora sí sobrevive a `config:cache`)

```bash
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force

php artisan config:cache
php artisan route:cache
php artisan view:cache
```

`config:cache` **después** de definir CORS y Sanctum. Si cacheas con el `.env` a medias, el front del dominio real recibirá CORS de localhost.

Hoy **no** hace falta `queue:work` ni cron de `schedule:run`: no hay jobs ni tareas programadas. No dejes un worker zombi “porque el README local lo menciona”.

Health check: `https://api.casasylotes.com.co/up`

---

## 4. Primeros usuarios (producción)

`DatabaseSeeder` **no crea** `admin@admin.com` ni otras cuentas demo cuando `APP_ENV=production`. No uses `php artisan db:seed` completo.

Tras el seeder de roles:

```bash
php artisan user:create "Admin Sistema" sistema@casasylotes.com.co admin_sistema
php artisan user:create "Administrador" admin@casasylotes.com.co administrador
```

El comando pide la contraseña por prompt (mínimo 8 caracteres). Roles válidos: `admin_sistema`, `administrador`, `socio_gerencia`.

- `admin_sistema` entra a `/usuarios` (no al dashboard).
- `administrador` entra a `/dashboard` y opera el negocio.
- `socio_gerencia` es de solo lectura; créalo cuando lo necesites, igual con `user:create`.

Los usuarios nuevos (`user:create` o el panel) y los que ya existían al correr la migración `must_change_password` quedan marcados: en el primer ingreso (o tras un reset de un admin) solo pueden cambiar la contraseña o cerrar sesión. `password_changed_at` en null es primer ingreso; con fecha, reset de un admin. Quienes ya habían desbloqueado o cambiado la clave reciben fecha al migrar, para no mezclar los dos textos.

### Recuperar el acceso si el cambio de contraseña falla

Si todos los usuarios quedan bloqueados (no pueden completar `PUT /api/me/password`), en el servidor:

```bash
cd /var/www/cartera-api   # o la ruta real del proyecto

# Un usuario
php artisan user:unlock-password --email=santiago@casasylotes.com.co

# Todos
php artisan user:unlock-password --all
```

Eso solo pone `must_change_password = false`. Entran con la contraseña que ya tenían. No borra cuentas ni tokens.

---

## 5. Carga histórica de San Miguel

Base **vacía** recién migrada: importa **sin** `--fresh`.

```bash
php artisan import:san-miguel
```

El Excel vive en `app/imports/SAN_MIGUEL_AMORTIZACION_Y_PAGOS.xlsx` (i minúscula).

Invariantes esperados (aprox.): 55 lotes, 55 contratos `SM-LOTE-%`, 56 clientes SM, **653** transacciones, recaudo **$2.600.440.231**, Lote 1 `principal_paid` de la inicial = `10500000.00`.

`$2.600.440.231` / 653 transacciones es la **línea base de la carga histórica** de San Miguel, no un techo. Los pagos posteriores (cuotas, extraordinarios o `interes_diferido`) la mueven hacia arriba de forma esperada: no es un descuadre.

### `--fresh` — no lo uses en producción salvo desastre consciente

`php artisan import:san-miguel --fresh` **borra** los contratos de San Miguel (incluidos los de PRUEBA), deja los lotes en `disponible` y no borra los PDF de recibos del disco. Es irreversible para esos contratos.

**Antes de `--fresh` en cualquier servidor con datos reales:** backup de PostgreSQL **y** de `storage/app/private`. Ver sección 8.

---

## 6. Front (SPA)

En la máquina donde construyas (puede ser tu Mac o el VPS):

```bash
git clone <url-del-repo-cartera-front> cartera-front
cd cartera-front
npm ci
npx ng build
```

`ng build` usa la configuración `production` por defecto. Eso reemplaza `environment.ts` por `environment.prod.ts` (`apiUrl`: `https://api.casasylotes.com.co/api`).

El artefacto queda siempre en `dist/browser/` (no depende del nombre del proyecto). Cópialo al docroot del front en el VPS (por ejemplo `/var/www/cartera-front/browser`).

Comprueba el bundle **antes** de publicar:

```bash
grep -R "api.casasylotes.com.co" dist/browser
grep -R "127.0.0.1:8000" dist/browser && echo "FALLO: sigue la URL local" || echo "OK: no hay localhost"
```

`ng serve` en desarrollo **no** usa `environment.prod.ts`; local sigue en `http://127.0.0.1:8000/api`.

---

## 7. nginx

SSL ya está. Ajusta `root`, `fastcgi_pass` (socket de php-fpm) y rutas de certificados a las reales del VPS.

### API — `api.casasylotes.com.co`

```nginx
server {
    listen 443 ssl http2;
    server_name api.casasylotes.com.co;

    root /var/www/cartera-api/public;
    index index.php;

    ssl_certificate     /etc/ssl/casasylotes/api.fullchain.pem;
    ssl_certificate_key /etc/ssl/casasylotes/api.privkey.pem;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        # Para que Laravel vea HTTPS detrás de nginx (trustProxies + X-Forwarded-Proto)
        fastcgi_param HTTPS on;
        fastcgi_param HTTP_X_FORWARDED_PROTO https;
        fastcgi_param HTTP_X_FORWARDED_FOR $proxy_add_x_forwarded_for;
        fastcgi_param HTTP_X_FORWARDED_HOST $host;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    client_max_body_size 12M;
}

server {
    listen 80;
    server_name api.casasylotes.com.co;
    return 301 https://$host$request_uri;
}
```

### SPA — `cartera.casasylotes.com.co`

```nginx
server {
    listen 443 ssl http2;
    server_name cartera.casasylotes.com.co;

    root /var/www/cartera-front/browser;
    index index.html;

    ssl_certificate     /etc/ssl/casasylotes/front.fullchain.pem;
    ssl_certificate_key /etc/ssl/casasylotes/front.privkey.pem;

    # Angular: todas las rutas de la SPA caen en index.html
    location / {
        try_files $uri $uri/ /index.html;
    }

    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff2?)$ {
        expires 7d;
        add_header Cache-Control "public, immutable";
        try_files $uri =404;
    }
}

server {
    listen 80;
    server_name cartera.casasylotes.com.co;
    return 301 https://$host$request_uri;
}
```

`php artisan config:cache` debe haberse hecho con `CORS_ALLOWED_ORIGINS=https://cartera.casasylotes.com.co`. Sin eso el navegador bloquea las llamadas del front al API.

---

## 8. Respaldos (dos almacenes)

Hay **dos** sitios de verdad. Restaurar solo la base deja recibos huérfanos o transacciones sin PDF.

1. **PostgreSQL** — contratos, cuotas, transacciones, recaudo.
2. **Archivos** — `storage/app/private/` (recibos PDF subidos al registrar pagos). No pasan por `public/storage`.

Ejemplo diario (ajusta usuario, base y destino):

```bash
STAMP=$(date +%F)
BACKUP_DIR=/var/backups/cartera
mkdir -p "$BACKUP_DIR"

pg_dump -Fc -d cartera -f "$BACKUP_DIR/cartera-$STAMP.dump"
tar -czf "$BACKUP_DIR/receipts-$STAMP.tar.gz" -C /var/www/cartera-api storage/app/private
```

Guarda copias fuera del VPS. Prueba un restore en frío al menos una vez.

`--fresh` borra filas `Receipt` en la base y **no** borra los PDF del disco: tras un `--fresh` a ciegas quedan archivos huérfanos y se pierde el histórico de contratos.

---

## 9. Actualizaciones posteriores

```bash
cd /var/www/cartera-api
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Front: `git pull`, `npx ng build`, sustituir el contenido de `browser/`.

No ejecutes `import:san-miguel --fresh` en un pull rutinario.
