# GlobalBusinessOne - Gestión de Extractos Bancarios (Primer Corte)

## 📌 Resumen del Proyecto
**GlobalBusinessOne** es una solución web desarrollada en Laravel 11 para la gestión avanzada de datos en SAP Business One. Este primer corte se enfoca en la **Limpieza Masiva de Extractos Bancarios (Tabla OBNK)**, permitiendo filtrar y eliminar miles de registros de forma segura y asíncrona.

---

## 🛠️ Stack Tecnológico
- **Framework**: Laravel 11.x
- **PHP**: 8.5 (Imagen Docker personalizada con drivers ODBC para SQL Server)
- **Base de Datos App**: MS SQL Server (`192.168.0.168\global`)
- **Integración SAP**: Service Layer API (`192.168.0.100:50000`)
- **Infraestructura**: Docker + Laravel Sail
- **Frontend**: AdminLTE 3 (Admin Panel) + DataTables.net

---

## 🚀 Funcionalidades Implementadas

### 1. Sistema de Login Multi-Sociedad
- El usuario puede elegir entre la base de datos **PRODUCTIVA (MANGO BAJITO)** y **PRUEBA (ZZZ_MB)** directamente en el login.
- La sesión de SAP se genera dinámicamente y se almacena de forma segura en la sesión del usuario (`b1_token`), permitiendo que múltiples usuarios trabajen en sociedades distintas simultáneamente.

### 2. Módulo de Gestión de Bancos (OBNK)
- **Consulta Avanzada**:
    - Filtro por **Nombre de la Cuenta** usando lógica `LIKE` (vía `substringof` en Service Layer).
    - Filtro opcional por **Código de Cuenta**.
    - Filtro por rango de fechas (`DueDate`).
    - Exclusión automática de registros ya conciliados (`ExternalCode eq null`).
- **Visualización de Alto Rendimiento**:
    - Capacidad de detectar el conteo total de registros en SAP (ej. 11,974 registros).
    - Previsualización limitada a los primeros 500 registros para mantener la fluidez del navegador.
    - DataTable en español con columnas: Sequence, AcctCode, AcctName, DueDate, CreateDate, DebAmount, CredAmnt, Memo.

### 3. Borrado Masivo Asíncrono
- Para evitar el "timeout" del servidor al borrar miles de registros, se implementó un sistema de **Colas (Jobs)**.
- El sistema obtiene todos los IDs (`Sequence`) de SAP y los envía a una cola de procesamiento.
- Los registros se borran uno a uno en segundo plano, garantizando la integridad de la base de datos de SAP.

### 4. Personalización y Branding
- **Logo**: Integración de `icon.jpg` en el Sidebar y en la pantalla de Login.
- **Favicon**: Configuración de `icon.jpg` como ícono de la pestaña del navegador con bypass de caché (`?v=3`).
- **Nomenclatura**: Rebranding total del proyecto de "Antigravity" a "GlobalBusinessOne".

---

## 📂 Archivos Clave del Proyecto
- `app/Services/SAPService.php`: Corazón de la integración con Service Layer.
- `app/Http/Controllers/BankPageController.php`: Lógica de consulta y disparo de procesos masivos.
- `app/Jobs/DeleteSapRecordJob.php`: Proceso individual de borrado asíncrono.
- `resources/views/bankpages/index.blade.php`: Interfaz de usuario avanzada con DataTables.
- `config/adminlte.php`: Configuración de menús y estética visual.

---

## ⚙️ Configuración del Servidor (.env)
- **DB_CONNECTION**: `sqlsrv`
- **DB_HOST**: `192.168.0.168` (Instancia `global`)
- **SAP_SERVICE_LAYER_URL**: `https://192.168.0.100:50000/b1s/v1`
- **SESSION_DRIVER**: `file` (Optimizado para estabilidad en Docker)

---
*Documento generado el 08 de Mayo de 2026 - Corte de Fase: Limpieza de Extractos Operativa.*
