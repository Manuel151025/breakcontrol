# Sistema de Inventario y Gestión — Panadería
**Versión:** 1.0 | **Entrega:** 8 de abril de 2026 | **Stack:** PHP + MySQL + Bootstrap 5

---

## Instalación en XAMPP

1. Copia la carpeta `panaderia` en `C:\xampp\htdocs\`
2. Importa `panaderia_bd.sql` en phpMyAdmin
3. Abre el navegador en `http://localhost/panaderia`
4. Ingresar con:
   - **Usuario:** `propietario`
   - **Contraseña:** `admin123`

---

## Estructura del Proyecto

```
panaderia/
├── config/
│   ├── app.php          # Constantes globales de la app
│   └── db.php           # Conexión PDO a MySQL
├── includes/
│   ├── sesion.php       # Autenticación y control de sesión
│   └── funciones.php    # Funciones auxiliares reutilizables
├── modules/
│   ├── inventario/      # EPIC-01 — La Despensa
│   ├── recetas/         # EPIC-02 — El Libro de Recetas
│   ├── compras/         # EPIC-03 — El Proveedor Automático
│   ├── finanzas/        # EPIC-04 — El Contador
│   └── tablero/         # EPIC-05 — El Tablero
├── views/
│   └── layouts/
│       ├── header.php   # Navbar + apertura del HTML
│       └── footer.php   # Scripts JS + cierre del HTML
├── assets/
│   ├── css/estilos.css  # Estilos propios
│   └── js/app.js        # JavaScript general
├── index.php            # Punto de entrada (redirige al tablero)
├── login.php            # Pantalla de login
└── logout.php           # Cierre de sesión
```

---

## Reglas de Negocio Implementadas

| Regla | Dónde |
|-------|-------|
| FIFO — lotes por fecha de ingreso | `sp_descontar_fifo` en la BD |
| Merma 6% en harina | Campo `aplica_merma` en `receta_ingrediente` |
| Alerta variación de precio > 5% | `historial_precio` + función PHP |
| Revisión automática de stock al login | `sp_revision_stock_diaria` en la BD |
| No compras los domingos | `esHoyDomingo()` en `funciones.php` |

---

## Sprints

| Sprint | Fechas | Módulo |
|--------|--------|--------|
| 1 — Levantamiento | 19 Feb – 25 Feb | Datos iniciales |
| 2 — Inventario y Recetas | 26 Feb – 4 Mar | EPIC-01, EPIC-02 |
| 3 — Compras | 5 Mar – 11 Mar | EPIC-03 |
| 4 — Finanzas | 12 Mar – 18 Mar | EPIC-04 |
| 5 — Dashboard | 19 Mar – 25 Mar | EPIC-05 |
| 6 — Pruebas | 26 Mar – 1 Abr | QA |
| 7 — Entrega | 2 Abr – 8 Abr | Despliegue |
