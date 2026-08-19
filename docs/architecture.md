# 📘 Guía de Arquitectura Backend - SGC (Laravel 11)

Este documento describe la estructura física y las reglas de diseño obligatorias para el desarrollo del Backend del **Sistema de Gestión de Cartera (SGC)**. El proyecto está construido bajo una **Clean Architecture** orientada a servicios y dominios, garantizando la separación de responsabilidades y la escalabilidad del sistema.

---

## 📂 Estructura de Directorios (`app/`)

El código fuente en PHP se organiza estrictamente bajo la siguiente jerarquía:

```text
app/
├── DTOs/                  # Objetos blindados para transportar datos seguros entre capas
├── Enums/                 # Estados inmutables del sistema (ej. DISPONIBLE, VENDIDO, MORA)
├── Http/
│   ├── Controllers/       # Controladores REST: reciben peticiones, validan y delegan a servicios
│   ├── Middleware/        # Filtros de seguridad y control de acceso (ej. RBAC, Tokens)
│   └── Requests/          # Escudos de validación (FormRequests) para los datos de entrada
├── Jobs/                  # Tareas asíncronas en segundo plano (Colas / Workers)
├── Listeners/             # Manejadores de eventos del sistema (ej. notificaciones y auditoría)
├── Models/                # Modelos de Eloquent ORM conectados a las tablas de PostgreSQL
├── Providers/             # Proveedores de servicios y configuración del framework
├── Services/              # Núcleo de negocio y motores financieros organizados por dominio:
│   ├── Collection/        # Lógica de recaudos en cascada y validación de lotes
│   ├── Financial/         # Motor matemático (tablas de amortización, intereses, saldos)
│   └── Security/          # Auditoría de cambios y control de permisos
└── Traits/                # Clases reutilizables (ej. ApiResponse para estandarizar JSON)