# SGC (Sistema de Gestión de Cartera) - Backend

API REST desarrollada con **Laravel** para el **Sistema de Gestión de Cartera (SGC)**. Este backend maneja la lógica de negocio, autenticación, amortizaciones, control de recaudos y notificaciones automatizadas.

---

## 🚀 Arquitectura del Backend (Laravel 11)

El proyecto sigue una estructura limpia (Clean Architecture) orientada a servicios, diseñada para desacoplar la lógica de negocio y garantizar la mantenibilidad y escalabilidad del sistema:

- **`app/Http/Controllers/`**: Controladores REST encargados de recibir las peticiones de la SPA, validar el `request` inicial y delegar el trabajo a las clases de servicio.
- **`app/Http/Middleware/`**: Filtros de seguridad y control de acceso (ej. verificación de roles y permisos RBAC antes de llegar a los controladores).
- **`app/Http/Requests/`**: Escudos de validación de datos de entrada (FormRequests) para asegurar la integridad de la información.
- **`app/Services/`**: Núcleo del sistema. Aquí reside la lógica pesada y los motores financieros, organizados por dominios:
  - `Security/`: Gestión de auditoría y permisos.
  - `Financial/`: Motor matemático para tablas de amortización, cálculo de intereses y saldos.
  - `Collection/`: Procesamiento de recaudos en cascada y validación de lotes.
- **`app/DTOs/`**: Objetos blindados para transportar datos de manera segura y estructurada entre capas.
- **`app/Enums/`**: Definición de estados inmutables del sistema (ej. estados de contratos: `DISPONIBLE`, `VENDIDO`, `MORA`).
- **`app/Traits/`**: Lógica reutilizable (ej. `ApiResponse` para estandarizar las respuestas JSON).
- **`app/Models/`**: Representación de las tablas de PostgreSQL utilizando Eloquent ORM.
- **`app/Jobs/` & `app/Listeners/`**: Gestión de tareas asíncronas, eventos en segundo plano y notificaciones automatizadas.

---

## 📦 Instalación y Configuración Local

Sigue estos pasos para levantar el backend en tu entorno local:

1. **Clonar el repositorio:**

   ```bash
   git clone https://github.com/joburbanop/cartera-api.git
   cd cartera-api
   ```

2. **Instalar dependencias de PHP:**

   ```bash
   composer install
   ```

3. **Configurar el archivo de entorno:**

   Copia el archivo de ejemplo y configura tus credenciales de base de datos:

   ```bash
   cp .env.example .env
   ```

   *Edita el archivo `.env` y ajusta los parámetros de conexión a tu base de datos PostgreSQL.*

4. **Generar la llave de la aplicación:**

   ```bash
   php artisan key:generate
   ```

5. **Ejecutar migraciones de base de datos:**

   ```bash
   php artisan migrate
   ```

6. **Iniciar el servidor local (con Herd o Artisan):**

   Si usas Laravel Herd, el proyecto ya estará disponible automáticamente en `http://cartera-api.test`. O bien, puedes usar:

   ```bash
   php artisan serve
   ```

---

## ⚙️ Comandos Útiles

- **Cargar el histórico de San Miguel:**

  ```bash
  php artisan import:san-miguel --fresh
  ```

  El archivo por defecto es `app/imports/SAN_MIGUEL_AMORTIZACION_Y_PAGOS.xlsx`.

  | Fase | Qué hace |
  |---|---|
  | **0** `--fresh` | Borra contratos de San Miguel (incluidos los de PRUEBA) y deja los lotes en `disponible`. Es opt-in: sin el flag no se borra nada. |
  | **1** | Importa contratos, titulares y transacciones desde el historial derecho del Excel (el importador original). |
  | **2** | Overlay de la tabla izquierda (Nper, cuota, extra, intereses, amortización, saldo) en 42 lotes. Cruza por recibo y usa la fecha de la transacción como `payment_date`. |
  | **3** | Seis abonos huérfanos sobre la cuota #1 (lotes 17, 18, 34, 37, 38, 42), sin tocar `receipt_number` ni transacciones. |
  | **4** | Cascada de vencimientos (mismo día del mes o fin de mes) en 13 contratos. Va al final para no pisar las fechas del overlay. |

  Las fases 2-4 **solo** corren con el libro oficial (`SAN_MIGUEL_AMORTIZACION_Y_PAGOS.xlsx`). Un Excel de prueba o `--dry-run` se detiene en la fase 1.

  **Excluidos del overlay (fase 2):**
  - Lotes **3, 16 y 54**: el Excel no cuadra con la caja de forma segura (extras no anotados, pagos cortos mezclados con rangos CUOTA n–m mal pintados). Quedan como sale la cascada del historial.
  - Lotes **6 y 45**: plan comercial PDF (`is_custom_plan`, 48 `ContractPaymentPromise`, precios $130.192.851 / $130.643.360). La tabla francesa del Excel no es la fuente.

  `--solo-lote=N` limita la fase 1 (y 2-4 si aplica) a esa pestaña. `--dry-run` valida el Excel sin escribir.

- **Ejecutar colas (Workers) en desarrollo:**

  ```bash
  php artisan queue:work
  ```

- **Ejecutar el programador de tareas (Scheduler):**

  ```bash
  php artisan schedule:work
  ```

---

## 📄 Licencia

Este proyecto es software privativo / de uso interno bajo los términos establecidos por la organización.

---

## 📐 Modelo entidad-relación

Ver diagrama entidad-relación(https://drive.google.com/file/d/1vqlgz_ior4u0PrJBugpQ7I1yY-lSVb6Z/view?usp=sharing)