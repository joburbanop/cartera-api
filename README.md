```markdown
# SGC (Sistema de Gestión de Cartera) - Backend

API REST desarrollada con **Laravel** para el **Sistema de Gestión de Cartera (SGC)**. Este backend maneja la lógica de negocio, autenticación, amortizaciones, control de recaudos y notificaciones automatizadas.

---

## 🚀 Arquitectura del Proyecto

El proyecto sigue una estructura limpia orientada a servicios para desacoplar la lógica de negocio de los controladores:

* **`app/Http/Controllers/`**: Controladores REST para la API que atienden las peticiones de la SPA.
* **`app/Services/`**: Clases de servicio donde reside la lógica pesada (motor de amortización, reglas de recaudo y auditoría).
* **`app/Models/`**: Modelos Eloquent para la interacción con la base de datos PostgreSQL.
* **`app/Jobs/ & Listeners/`**: Gestión de tareas en segundo plano (Workers) y eventos asíncronos (como notificaciones por WhatsApp).

---

## 🚀 Arquitectura del Proyecto

El proyecto sigue una estructura limpia (Clean Architecture) orientada a servicios, diseñada para desacoplar la lógica de negocio y garantizar la mantenibilidad y escalabilidad del sistema:

*   **`app/Http/Controllers/`**: Controladores REST encargados de recibir las peticiones de la SPA, validar el `request` inicial y delegar el trabajo a las clases de servicio.
*   **`app/Services/`**: Núcleo del sistema. Aquí reside la lógica pesada y los motores financieros:
    *   `Security/`: Gestión de auditoría y permisos.
    *   `Financial/`: Motor matemático para tablas de amortización, cálculo de intereses y saldos.
    *   `Collection/`: Procesamiento de recaudos en cascada (mora -> cuota) y validación de lotes.
*   **`app/DTOs/`**: Objetos blindados para transportar datos de manera segura y estructurada entre capas, evitando pasar arreglos sueltos.
*   **`app/Enums/`**: Definición de estados inmutables del sistema (ej. estados de contratos: `DISPONIBLE`, `VENDIDO`, `MORA`).
*   **`app/Traits/`**: Lógica reutilizable en controladores y modelos (ej. `ApiResponse` para estandarizar las respuestas JSON).
*   **`app/Models/`**: Representación de las tablas de PostgreSQL utilizando Eloquent ORM.
*   **`app/Jobs/` & `app/Listeners/`**: Gestión de tareas asíncronas y eventos en segundo plano, incluyendo el despacho de mensajes automatizados a través de la API de WhatsApp.
---

## 📦 Instalación y Configuración Local

Sigue estos pasos para levantar el backend en tu entorno local:

1. **Clonar el repositorio:**
   ```bash
   git clone [https://github.com/joburbanop/cartera-api.git	](https://github.com/joburbanop/cartera-api.git)
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

* **Ejecutar colas (Workers) en desarrollo:**
```bash
php artisan queue:work

```


* **Ejecutar el programador de tareas (Scheduler):**
```bash
php artisan schedule:work

```



---

## 📄 Licencia

Este proyecto es software privativo / de uso interno bajo los términos establecidos por la organización.
---