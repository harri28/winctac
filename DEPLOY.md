# Despliegue en Ubuntu Server

Estado real del despliegue de Selvadigital Ecommerce en producción, con el
código conectado a GitHub (`git@github.com:harri28/winctac.git`) y clonado en
el servidor por SSH mediante una deploy key dedicada.

Datos de este despliegue:
- Servidor: `161.132.4.82` (VPS compartido — ver nota abajo, ya tenía otro sitio)
- Dominio: `wintac.shop` / `www.wintac.shop`, con SSL (Let's Encrypt / certbot)
- Ruta del código: `/var/www/ecomerce`
- La app usa `BASE_URL` (no rutas fijas `/ecomerce`), así que se sirve
  correctamente en la raíz del dominio

## ⚠️ Este VPS es compartido — no es un servidor limpio

El servidor ya tenía otro proyecto corriendo (`sys360.cloud`, una app
Python/uvicorn en el puerto 8000) cuando se desplegó esta tienda. Esto
determinó decisiones importantes que hay que respetar en cualquier cambio
futuro:

- **nginx** es el único servidor web activo en los puertos 80/443 (no Apache
  — Apache está instalado pero deshabilitado con `systemctl disable apache2`
  porque nginx ya tenía esos puertos ocupados por `sys360.cloud`).
- **PHP se sirve vía PHP-FPM** (`php8.1-fpm`), no `mod_php`/Apache.
- **PostgreSQL tiene 3 clusters** en este servidor (versiones 12, 14, 17).
  El cluster v14 en el **puerto 5433** estaba vacío y se usó para esta tienda;
  el cluster v12 en el puerto 5432 pertenece al otro sitio (base `structure`,
  rol `appuser`) — **nunca tocar esa base**.

Si necesitas tocar la configuración de nginx o PostgreSQL en este servidor,
revisa primero qué más hay corriendo (`ls /etc/nginx/sites-enabled/`,
`pg_lsclusters`, `ss -tlnp`) antes de modificar o reiniciar nada.

## 1. Paquetes del sistema

Ya instalados en este servidor: `nginx` (preexistente), `postgresql` (cluster
v14 puerto 5433, preexistente), `php8.1-fpm`, `php8.1-pgsql`, `php8.1-mbstring`,
`git`, `certbot` + `python3-certbot-nginx`.

En un servidor nuevo desde cero (sin nginx/otro sitio ya corriendo), sería:

```bash
apt update && apt upgrade -y
apt install -y nginx postgresql postgresql-contrib \
  php-fpm php-pgsql php-mbstring php-curl php-xml git \
  certbot python3-certbot-nginx
```

## 2. Deploy key SSH del servidor hacia GitHub

El servidor tiene su propia llave (`~/.ssh/id_ed25519`, comentario
`ecomerce-server`), agregada en GitHub como **Deploy Key de solo lectura** en
`https://github.com/harri28/winctac/settings/keys` (sin "Allow write access"
— el servidor solo hace `git pull`, nunca `git push`).

## 3. Repositorio

```bash
git clone git@github.com:harri28/winctac.git /var/www/ecomerce
```

## 4. Base de datos PostgreSQL (cluster v14, puerto 5433)

```bash
sudo -u postgres psql -p 5433 -c "CREATE ROLE selvadigital_app LOGIN PASSWORD '...';"
sudo -u postgres psql -p 5433 -c "CREATE DATABASE selvadigital OWNER selvadigital_app;"
```

La contraseña real está únicamente en el pool de PHP-FPM (paso 5) — no está
en ningún archivo del repositorio.

## 5. Pool dedicado de PHP-FPM (credenciales vía variables de entorno)

El código lee `DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS` y `ECOMMERCE_BASE_URL`
con `getenv()` (`config/database.php`, `database/setup.php`, `config/app.php`).
PHP-FPM borra el entorno por defecto, así que hace falta `clear_env = no` y
declarar cada variable explícitamente en el pool:

`/etc/php/8.1/fpm/pool.d/wintac.conf`:

```ini
[wintac]
user = www-data
group = www-data
listen = /run/php/wintac.sock
listen.owner = www-data
listen.group = www-data
pm = dynamic
pm.max_children = 5
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
clear_env = no
env[DB_HOST] = 127.0.0.1
env[DB_PORT] = 5433
env[DB_NAME] = selvadigital
env[DB_USER] = selvadigital_app
env[DB_PASS] = ...
env[ECOMMERCE_BASE_URL] = https://wintac.shop
```

```bash
systemctl restart php8.1-fpm
```

Cambios en este archivo (por ejemplo, rotar la contraseña) requieren
`systemctl restart php8.1-fpm` para aplicarse.

## 6. Server block de nginx

`/etc/nginx/sites-available/wintac.shop` (enlazado en `sites-enabled`):

```nginx
server {
    listen 80;
    server_name wintac.shop www.wintac.shop;
    root /var/www/ecomerce;
    index index.php index.html;

    location ^~ /database/ {
        deny all;
        return 404;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/wintac.sock;
    }

    location ~* \.(jpg|jpeg|png|gif|webp|svg|css|js|ico)$ {
        expires 30d;
        access_log off;
    }
}
```

`certbot --nginx` añadió automáticamente el bloque `listen 443 ssl` y el
redirect 80→443 sobre este mismo archivo — no lo pises al editarlo a mano.

```bash
ln -sf /etc/nginx/sites-available/wintac.shop /etc/nginx/sites-enabled/wintac.shop
nginx -t && systemctl reload nginx
```

## 7. Permisos

```bash
chown -R www-data:www-data /var/www/ecomerce/uploads
find /var/www/ecomerce/uploads -type d -exec chmod 775 {} \;
find /var/www/ecomerce/uploads -type f -exec chmod 664 {} \;
```

## 8. Esquema de base de datos (ya ejecutado)

`database/setup.php` y `database/migrar.php` ya se corrieron una vez contra
`selvadigital` (cluster 5433). El bloque `location ^~ /database/ { deny all; }`
del paso 6 ya está activo, así que esas URLs devuelven 404 públicamente.

Si necesitas correr una migración nueva más adelante: comenta temporalmente
ese bloque `location`, recarga nginx, visita la URL, y vuelve a comentarlo.

## 9. HTTPS (Let's Encrypt) — ya configurado

```bash
certbot --nginx -d wintac.shop -d www.wintac.shop --redirect --agree-tos -m petram.control@gmail.com
```

Certbot renueva automáticamente vía systemd timer. El certificado actual
vence el 2026-11-16.

## 10. Flujo de actualización (deploy de cambios nuevos)

Desde tu máquina local: `git push origin main` como siempre.

En el servidor, para publicar la nueva versión:

```bash
cd /var/www/ecomerce
git pull origin main
chown -R www-data:www-data uploads
```

No hace falta reiniciar nginx ni PHP-FPM para cambios de código PHP normales
— solo si tocas el pool (`wintac.conf`) o el server block de nginx.

## Credenciales por defecto — cambiar de inmediato

El seed inicial crea un admin con `admin@tienda.com` / `admin123`. Entra a
`https://wintac.shop/admin/login.php` y cambia esa contraseña cuanto antes;
son credenciales públicas en el código fuente.
