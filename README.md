# Sistema Web — D.A SERVICE S.A.S.

Implementación de un sistema web para la gestión de desprendibles de nómina, medición de tickets de soporte y el seguimiento de procesos comerciales en la empresa D.A SERVICE S.A.S. en Yumbo.

## Información del proyecto

- **Universidad:** Institución Universitaria Antonio José Camacho
- **Programa:** Ingeniería de Sistemas
- **Integrantes:** Edwin Andrés Solís Borja, Juan Camilo Ocampo Loboa
- **Docente:** Javier Pérez Campo
- **Empresa:** D.A SERVICE S.A.S. (Yumbo, Valle del Cauca)
- **Metodología:** Scrum + ciclo de vida incremental

## Módulos del sistema

1. **Nómina:** registro de empleados, consulta y descarga de desprendibles, certificados laborales, solicitud/aprobación de permisos, consulta de vacaciones.
2. **Soporte técnico:** registro, asignación, actualización y cierre de tickets; calificación del servicio.
3. **Seguimiento comercial:** historial de clientes, oportunidades comerciales, interacciones, indicadores de desempeño.

## Tecnologías

- PHP 8.x
- MySQL / MariaDB (PDO)
- HTML, CSS (Flexbox / CSS Grid), JavaScript
- Sesiones de PHP para autenticación
- Entorno de desarrollo local: XAMPP / LAMP

## Estructura de carpetas

```
/nomina
/tickets
/comercial
/config
/includes
/usuarios
/assets
```

## Convención de ramas

- **main** — versión estable del sistema.
- **dev** — integración de los avances de cada sprint.
- **feature/nombre-modulo** — una rama por historia de usuario en desarrollo (ej. `feature/registro-empleados`).

Los commits deben describir claramente el cambio realizado y referenciar el ID de la historia correspondiente (ej. `HU-01: agregar formulario de registro de empleados`).

## Seguimiento

- **Tablero Scrum (Trello):** https://trello.com/b/mQjUoul5/proyecto-de-grado-da-service-sas
- **Product Backlog, historias de usuario y plan de sprints:** ver carpeta `/docs` del repositorio (Corte 1).
