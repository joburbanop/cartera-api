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

## 🛠️ Requisitos del Sistema

* **PHP** >= 8.2
* **Composer**
* **PostgreSQL**
* **Laravel Herd** (Recomendado para entorno de desarrollo local)

---

## 📦 Instalación y Configuración Local

Sigue estos pasos para levantar el backend en tu entorno local:

1. **Clonar el repositorio:**
   ```bash
   git clone [https://github.com/tu-usuario/cartera-api.git](https://github.com/tu-usuario/cartera-api.git)
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

```

```