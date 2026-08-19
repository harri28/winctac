# Despliegue en Ubuntu Server

Guía para publicar Selvadigital Ecommerce en un servidor Ubuntu, con el código
conectado a GitHub (`git@github.com:harri28/winctac.git`) y comunicándose por SSH.

Datos de este despliegue:
- Servidor: `161.132.4.82`
- Dominio: `wintac.shop` (⚠️ revisa la nota de DNS al final — el registro A
  actual apunta a `2.57.91.91`, no a `161.132.4.82`; corrígelo en tu proveedor DNS)
- Ruta en el servidor: `/var/www/ecomerce`
- La app ahora usa `BASE_URL` en vez de rutas fijas `/ecomerce`, así que se sirve
  correctamente en la raíz del dominio (`https://wintac.shop/`)

Todos los comandos de esta guía se ejecutan **dentro del servidor** (conéctate
primero con `ssh tu_usuario@161.132.4.82`), salvo que se indique lo contrario.

## 1. Paquetes del sistema

```bash
sudo apt update
sudo apt install -y apache2 postgresql postgresql-contrib \
  php libapache2-mod-php php-pgsql php-mbstring php-curl php-xml git
```

## 2. Llave SSH del servidor para GitHub (deploy key)

El servidor necesita su propia llave SSH para poder clonar/actualizar el
repositorio por SSH, independiente de tu llave personal.

```bash
ssh-keygen -t ed25519 -C "ecomerce-server" -f ~/.ssh/id_ed25519 -N ""
cat ~/.ssh/id_ed25519.pub
```

Copia esa clave pública y agrégala en GitHub como **Deploy Key** (no como llave
de tu cuenta personal):

`https://github.com/harri28/winctac/settings/keys` → **Add deploy key** → pega
la clave → dejar **sin marcar** "Allow write access" (solo necesita leer/hacer
`git pull`, no `git push` desde el servidor).

Prueba la conexión:

```bash
ssh -T git@github.com
# "Hi harri28/winctac! You've successfully authenticated..."
```

## 3. Clonar el repositorio

```bash
sudo mkdir -p /var/www/ecomerce
sudo chown $USER:$USER /var/www/ecomerce
git clone git@github.com:harri28/winctac.git /var/www/ecomerce
```

## 4. Base de datos PostgreSQL

Crea una contraseña fuerte para producción — **no reutilices** la `1234` de
desarrollo local.

```bash
sudo -u postgres psql -c "CREATE DATABASE selvadigital;"
sudo -u postgres psql -c "ALTER USER postgres WITH PASSWORD 'PON_AQUI_UNA_CONTRASENA_FUERTE';"
```

(Si prefieres un usuario dedicado en vez de reutilizar `postgres`, créalo con
`CREATE USER selvadigital_app WITH PASSWORD '...';` y
`GRANT ALL PRIVILEGES ON DATABASE selvadigital TO selvadigital_app;`, y usa
ese usuario en `DB_USER` más abajo.)

## 5. Variables de entorno (credenciales y URL base)

El código lee estas variables con `getenv()` (`config/database.php`,
`database/setup.php`, `config/app.php`) — si no están definidas, usa los
valores de desarrollo local. Defínelas en el VirtualHost de Apache para que
sólo apliquen a este sitio.

## 6. VirtualHost de Apache

```bash
sudo tee /etc/apache2/sites-available/wintac.shop.conf > /dev/null <<'EOF'
<VirtualHost *:80>
    ServerName wintac.shop
    ServerAlias www.wintac.shop
    DocumentRoot /var/www/ecomerce

    SetEnv ECOMMERCE_BASE_URL "https://wintac.shop"
    SetEnv DB_HOST "127.0.0.1"
    SetEnv DB_PORT "5432"
    SetEnv DB_NAME "selvadigital"
    SetEnv DB_USER "postgres"
    SetEnv DB_PASS "PON_AQUI_LA_MISMA_CONTRASENA_FUERTE"

    <Directory /var/www/ecomerce>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/wintac-error.log
    CustomLog ${APACHE_LOG_DIR}/wintac-access.log combined
</VirtualHost>
EOF

sudo a2ensite wintac.shop.conf
sudo a2enmod php* rewrite
sudo apache2ctl configtest
sudo systemctl reload apache2
```

## 7. Permisos

```bash
sudo chown -R www-data:www-data /var/www/ecomerce/uploads
sudo find /var/www/ecomerce/uploads -type d -exec chmod 775 {} \;
sudo find /var/www/ecomerce/uploads -type f -exec chmod 664 {} \;
```

## 8. Crear el esquema (una sola vez)

Con el DNS ya apuntando al servidor (ver nota final), visita en el navegador:

1. `https://wintac.shop/database/setup.php` — crea las tablas
2. `https://wintac.shop/database/migrar.php` — aplica migraciones

**Después de ejecutarlos una vez, bloquea el acceso público a `/database/`**
(esos scripts pueden volver a ejecutarse si alguien conoce la URL):

```bash
sudo tee -a /etc/apache2/sites-available/wintac.shop.conf > /dev/null <<'EOF'
<Directory /var/www/ecomerce/database>
    Require all denied
</Directory>
EOF
sudo systemctl reload apache2
```

(Vuelve a permitir el acceso temporalmente sólo si necesitas correr una
migración nueva más adelante.)

## 9. HTTPS (Let's Encrypt)

```bash
sudo apt install -y certbot python3-certbot-apache
sudo certbot --apache -d wintac.shop -d www.wintac.shop
```

## 10. Flujo de actualización (deploy de cambios nuevos)

Desde tu máquina local: `git push origin main` como siempre.

En el servidor, para publicar la nueva versión:

```bash
cd /var/www/ecomerce
git pull origin main
sudo chown -R www-data:www-data uploads
```

## Nota importante: DNS

El registro DNS actual de `wintac.shop` es:

```
A     @     2.57.91.91      (⚠️ no coincide con el servidor 161.132.4.82)
CNAME www   wintac.shop
```

Antes de que `https://wintac.shop/` funcione contra este servidor, corrige el
registro **A** en tu proveedor DNS para que apunte a `161.132.4.82`. El TTL de
50s hará que el cambio se propague rápido.
