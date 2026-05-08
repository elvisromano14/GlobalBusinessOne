# GlobalBusinessOne

> Dashboard de integración con **SAP Business One** — Módulo de Limpieza Masiva de Extractos Bancarios (OBNK).

**Cliente:** Novedades Ribery, C.A. | **Hardware Key:** R0199859352

---

## Requisitos Previos

| Herramienta | Versión mínima | Descarga |
|-------------|---------------|---------|
| Docker Desktop | 4.x | [docker.com/products/docker-desktop](https://www.docker.com/products/docker-desktop) |
| Git | 2.x | [git-scm.com](https://git-scm.com) |
| Node.js | 20.x LTS | [nodejs.org](https://nodejs.org) |
| Composer | 2.x | [getcomposer.org](https://getcomposer.org) |

> **No necesitas PHP, PostgreSQL ni Redis instalados localmente.** Todo corre dentro de Docker.

---

## Instalación

### 1. Clonar el repositorio

```bash
git clone https://github.com/TU_USUARIO/GlobalBusinessOne.git
cd GlobalBusinessOne/antigravity
```

### 2. Configurar el entorno

```bash
# Copiar la plantilla de variables de entorno
cp .env.example .env
```

Edita el archivo `.env` y configura:

```env
# URL del SAP Service Layer de tu empresa
SAP_SERVICE_LAYER_URL=https://192.168.0.0:50000/b1s/v1

# Nombre de la sociedad SAP (CompanyDB)
SAP_COMPANY_DB=SBO_MI_EMPRESA_PRODUCTIVA

# Contraseña de PostgreSQL (puedes dejarla como está para desarrollo)
DB_PASSWORD=password
```

> ⚠️ **NUNCA** subas el archivo `.env` al repositorio. Está protegido en `.gitignore`.

### 3. Instalar dependencias PHP

```bash
composer install
```

> Si no tienes PHP local, puedes ejecutar Composer dentro del contenedor después del paso 4.

### 4. Levantar los contenedores Docker

```bash
docker compose up -d
```

Esto inicia 4 contenedores:

| Contenedor | Rol | Puerto |
|------------|-----|--------|
| `antigravity-laravel.test-1` | App Laravel + Nginx | `80` |
| `antigravity-queue-1` | Worker de colas (Jobs) | — |
| `antigravity-pgsql-1` | Base de datos PostgreSQL | `5432` |
| `antigravity-redis-1` | Cache + Cola de trabajos | `6379` |

### 5. Generar clave de aplicación

```bash
docker exec antigravity-laravel.test-1 php artisan key:generate
```

### 6. Ejecutar migraciones

```bash
docker exec antigravity-laravel.test-1 php artisan migrate
```

### 7. Instalar dependencias frontend

```bash
npm install
npm run dev
```

### 8. Acceder a la aplicación

Abre tu navegador en: **[http://localhost](http://localhost)**

---

## Uso

### Login
Ingresa con tus credenciales de SAP Business One. Puedes elegir entre la sociedad **Productiva** o **Prueba** en el formulario de login.

### Limpieza de Extractos Bancarios (OBNK)
1. Navega a **Gestión de Extractos Bancarios**.
2. Aplica los filtros (código de cuenta, nombre, rango de fechas).
3. Haz clic en **Consultar** — verás los primeros 20 registros y el **total real en SAP**.
4. Confirma el borrado con el botón **Borrar X registros**.
5. El proceso corre en **segundo plano** — la barra de progreso se actualiza automáticamente.

---

## Estructura del Proyecto

```
antigravity/
├── app/
│   ├── Http/Controllers/
│   │   ├── AuthController.php          # Autenticación SAP
│   │   └── BankPageController.php      # Consulta y limpieza OBNK
│   ├── Http/Middleware/
│   │   └── B1SessionMiddleware.php     # Protección de rutas
│   ├── Jobs/
│   │   └── ProcessMassCleanupJob.php   # Borrado masivo en background
│   └── Services/
│       └── SAPService.php              # Cliente del SAP Service Layer
├── resources/views/bankpages/
│   └── index.blade.php                 # UI con tabla y progreso
├── routes/web.php                      # Rutas de la aplicación
├── compose.yaml                        # Configuración Docker
├── .env.example                        # Plantilla de configuración
└── README.md
```

---

## Comandos Útiles

```bash
# Ver logs de la aplicación en tiempo real
docker exec antigravity-laravel.test-1 tail -f storage/logs/laravel.log

# Ver progreso del worker de colas
docker logs antigravity-queue-1 -f

# Reiniciar el worker (requerido después de cambios en Jobs)
docker restart antigravity-queue-1

# Acceder al shell del contenedor
docker exec -it antigravity-laravel.test-1 bash

# Limpiar caché de Laravel
docker exec antigravity-laravel.test-1 php artisan cache:clear
docker exec antigravity-laravel.test-1 php artisan config:clear

# Ver registros pendientes en Redis
docker exec antigravity-redis-1 redis-cli llen queues:default
```

---

## Notas de Conectividad

El contenedor Docker necesita acceder al servidor SAP en la red local. Si el SAP corre en `192.168.0.100`, el archivo `compose.yaml` ya incluye:

```yaml
extra_hosts:
    - 'host.docker.internal:host-gateway'
    - 'SRVSAP:192.168.0.100'
```

Si tu servidor SAP tiene una IP diferente, actualiza esta línea en `compose.yaml` y ejecuta:

```bash
docker compose down
docker compose up -d
```

---

## Variables de Entorno Clave

| Variable | Descripción | Ejemplo |
|----------|-------------|---------|
| `SAP_SERVICE_LAYER_URL` | URL completa del Service Layer | `https://192.168.0.100:50000/b1s/v1` |
| `SAP_COMPANY_DB` | Nombre de la base de datos SAP | `SBO_MANGO_BAJITO_PRODUCTIVA` |
| `QUEUE_CONNECTION` | Motor de colas (debe ser `redis`) | `redis` |
| `CACHE_STORE` | Motor de caché (debe ser `redis`) | `redis` |
| `DB_PASSWORD` | Contraseña de PostgreSQL | — |

---

## Solución de Problemas

### La aplicación no conecta con SAP
```bash
# Verificar conectividad desde el host
Test-NetConnection -ComputerName 192.168.0.100 -Port 50000

# Verificar desde dentro del contenedor
docker exec antigravity-laravel.test-1 curl -k https://192.168.0.100:50000/b1s/v1
```

### El cleanup solo borra 20 registros
Asegúrate de que el worker tiene el código más reciente:
```bash
docker restart antigravity-queue-1
```

### Error de sesión SAP expirada
El token `B1SESSION` expira en ~30 minutos. Vuelve a hacer login en la aplicación.

---

## Licencia

Uso interno exclusivo — Novedades Ribery, C.A. © 2026
