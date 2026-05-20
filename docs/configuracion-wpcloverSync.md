# Guía de Configuración — WPCloverSync

---

## ¿Qué hace este plugin?

Cuando un cliente hace un pedido en tu sitio web, el plugin **automáticamente envía ese pedido a tu Clover** — con los productos, modificadores, impuestos y todo. No tienes que entrar a Clover a registrar el pedido manualmente.

---

# PARTE 1 — Configuración en WordPress

---

## Paso 1 — WooCommerce: Impuestos

El plugin lee los impuestos directamente desde Clover y los muestra en el checkout automáticamente. **No necesitas configurar tax rates en WooCommerce.**

Si tienes tax rates configurados en WooCommerce, desactívalos para evitar cobro doble:

**WooCommerce → Settings → Tax → Standard Rates** → elimina cualquier fila existente → Save changes

> Los impuestos se muestran en el checkout como líneas de fee (ej: "Sales Tax (8.375%)") leídas desde la pestaña **Taxes and Fees** del plugin.

---

## Paso 2 — WooCommerce: Productos

Deja los productos con Tax status en **None** o **Taxable** — no importa, los impuestos los maneja el plugin directamente desde Clover.

---

## Paso 3 — Plugin: Pestaña API

**WordPress Admin → WPCloverSync → Settings → API**

| Campo | Qué poner |
|-------|-----------|
| Merchant ID | Tu ID de comerciante de Clover (Clover dashboard → Account & Setup → About) |
| API Token | Tu token de acceso (Clover → Your Apps → [tu app] → API Access) |
| API Base URL | `https://api.clover.com/v3/merchants/` (no cambiar) |

Usa el botón **"Test Connection"** para verificar que conecta correctamente.

---

## Paso 4 — Plugin: Pestaña Orders

**WPCloverSync → Settings → Orders**

| Ajuste | Explicación |
|--------|-------------|
| Auto Print Orders | Si está activado, la orden se manda automáticamente a la impresora de Clover cuando llega |
| Auto Mark as Paid | Marca la orden como pagada en Clover automáticamente |
| Payment Tender | El método de pago que registrará en Clover (ej: "Online", "Credit Card"). Usa el botón Reload para cargar los disponibles |
| Employee | El empleado asignado a las órdenes web. Usa Reload para cargar los de Clover |
| Default Order Type | Qué tipo de orden es (Delivery, Pickup, etc.) cuando no hay método de envío mapeado |
| Order Type Mapping | Mapea cada método de envío de WooCommerce a un tipo de orden en Clover |

---

## Paso 5 — Plugin: Pestaña Pricing

**WPCloverSync → Settings → Pricing**

Aquí configuras si quieres aplicar un **descuento general** a todos los productos del sitio.

| Ajuste | Explicación |
|--------|-------------|
| Enable Global Discount | Activa el descuento para todos los productos |
| Discount % | Porcentaje de descuento (ej: 10 = 10% off) |
| Apply to Modifiers | Si también aplica el descuento a los modificadores |

> Si activas esto, los precios en el sitio se mostrarán tachados con el precio descontado. Asegúrate que los precios en WooCommerce sean los precios SIN descuento.

---

## Paso 6 — Plugin: Pestaña Discounts

**WPCloverSync → Settings → Discounts**

Aquí puedes aplicar un descuento específico que ya tienes creado en Clover.

1. Click **"Reload Discounts"** para cargar los descuentos de tu cuenta Clover
2. Selecciona el descuento del dropdown — verás el nombre y el % entre paréntesis
3. Marca **"Apply Clover discount to all WooCommerce orders"**
4. Save

> Diferencia con Pricing: el descuento de Pricing lo calcula WooCommerce. El descuento de esta pestaña lo aplica Clover directamente sobre la orden en Clover.

---

## Paso 7 — Plugin: Pestaña Taxes and Fees

**WPCloverSync → Settings → Taxes and Fees**

Esta es la sección más importante para que los impuestos coincidan entre WooCommerce y Clover.

**Sección Tax Rates:**
- Verás una tabla con todos los impuestos configurados en tu Clover
- El que tiene la etiqueta **"Default"** es el impuesto principal (ej: Sales Tax 8.375%)
- Marca el checkbox de cada impuesto que quieres aplicar

**Comportamiento al marcar un tax:**
1. Se envía a Clover como parte de cada línea de la orden → aparece en el recibo de Clover
2. Se muestra como línea de fee en el checkout de WooCommerce → el cliente lo ve antes de pagar

> No necesitas configurar taxes en WooCommerce. El plugin lee nombre y porcentaje directamente desde Clover.

---

## Paso 8 — Plugin: Store Hours (opcional)

**WPCloverSync → Settings → Store Hours**

Si activas esta función, el sitio no permitirá hacer pedidos cuando el negocio está cerrado. Clover sincroniza los horarios automáticamente.

---

## Paso 9 — Plugin: Logs (para solucionar problemas)

**WPCloverSync → Settings → Logs**

- Activa **"Enable Logs"** si algo no funciona
- Los logs registran todo lo que hace el plugin en tiempo real
- Puedes ver los logs desde esa misma pestaña
- También se genera un archivo `logs/settings-audit.log` con cada cambio que se hace en la configuración del plugin (fecha, usuario, valor anterior y nuevo)

---

# PARTE 2 — Configuración en Clover

---

## 1. Catálogo de productos (Items)

Cada producto en WooCommerce necesita tener el **SKU = ID del item en Clover**.

**En Clover:**
- Inventory → Items → abre un item
- Copia el ID (aparece en la URL o en los detalles — es una secuencia de letras y números como `AB12CD34EF56G`)

**En WooCommerce:**
- Edita el producto → campo **SKU** → pega ese ID

> Si el SKU no coincide con el ID de Clover, el plugin omite ese item de la orden.

---

## 2. Modificadores

Los modificadores deben existir en el catálogo de Clover:
- **Clover:** Inventory → Modifier Groups → crea los grupos y modificadores con sus precios
- **WooCommerce:** el plugin importa los modificadores y los vincula automáticamente por su ID de Clover

---

## 3. Tax Rates

**Clover → Setup → Tax Rates**

Configura los impuestos que quieres cobrar:
- **Sales Tax (8.375%)** — marcado como Default (Clover lo aplica automáticamente)
- **Convenience Fee (3%)** — tax adicional para pedidos online

El plugin lee estos taxes desde la API de Clover y los muestra en la pestaña Taxes and Fees del plugin.

---

## 4. Order Types

**Clover → Setup → Order Types**

Crea los tipos de orden que necesitas, por ejemplo:
- Delivery
- Pickup
- Online Order

Estos se mapean con los métodos de envío de WooCommerce en el plugin (pestaña Orders → Order Type Mapping).

---

## 5. Employees

**Clover → Employees → Add Employee**

Crea un empleado llamado "Web Orders" o similar. Este empleado se asignará a todas las órdenes que lleguen desde el sitio web. Se selecciona en el plugin (pestaña Orders → Employee).

---

## 6. Tenders (métodos de pago)

**Clover → Setup → Tenders**

Asegúrate de tener un tender configurado para pagos online (ej: "Online Payment", "Website"). Este tender se usa para marcar la orden como pagada en Clover cuando llega desde el sitio web. Se selecciona en el plugin (pestaña Orders → Payment Tender).

---

## 7. Additional Charges (Service Charge)

**Clover → Setup → Additional Charges**

Si quieres cobrar un Convenience Fee:
1. Crea el cargo con nombre "Convenience Fee"
2. Asigna el porcentaje (ej: 3%)
3. Copia el ID del cargo (aparece en los detalles del cargo)
4. Pega ese ID en el plugin → pestaña Taxes and Fees → Service Charge ID

---

# Resumen del flujo completo

```
Cliente hace pedido en WooCommerce
          |
          v
WooCommerce calcula:
  producto + modificadores + Tax 8.375% + Convenience Fee 3%
          |
          v
Plugin envía orden a Clover automáticamente
(cuando el pedido cambia a estado "Processing")
          |
          v
Clover muestra en recibo:
  Items + modificadores + Tax 8.375% + Convenience Fee 3%
          |
          v
Clover marca la orden como pagada (automático)
          |
          v
Clover envía a impresora (si está activado)
```

---

# Solución de problemas comunes

| Problema | Causa probable | Solución |
|----------|---------------|----------|
| La orden no llega a Clover | SKU del producto vacío o incorrecto | Verifica que el SKU del producto = ID del item en Clover |
| Los impuestos no aparecen en checkout de WC | Tax rates no marcados | Marca los impuestos en la pestaña Taxes and Fees del plugin |
| Los totales no coinciden entre WC y Clover | Tax rates en WC configurados además del plugin | Elimina los tax rates de WooCommerce → Settings → Tax → Standard Rates |
| La orden llega duplicada a Clover | El status cambia dos veces | Normal — el plugin ignora duplicados automáticamente |
| Error de conexión | Token o Merchant ID incorrecto | Verifica credenciales en pestaña API |

---

*Generado por WPCloverSync — Ermis Media Production*
