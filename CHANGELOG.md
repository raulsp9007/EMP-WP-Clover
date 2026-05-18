# Changelog — WPCloverSync

All notable changes documented here. Each version = restore point (`git tag vX.X.X`).

---

## [Unreleased]
- Base price display in checkout Order Summary (in progress)

### Added
- Print Device selector in Orders tab — dropdown loads all Clover devices via `GET /devices` with Reload button
- `getDevices()` method in `OrderService.php`
- `clover_reload_devices` AJAX handler in `wp-clover-plugin.php`
- Checkout validation for Prevent Orders When Closed — blocks order submission if store/category is closed (previously only blocked Add to Cart)

### Fixed
- Auto-Print not working — `printOrder()` now sends `deviceRef.id` to Clover; without it Clover ignores the `/print_event` call
- Prevent Orders When Closed hierarchy corrected — store hours checked first as hard gate; if store is closed all items are blocked; if store is open, category-specific hours are then evaluated per item
- Prevent Orders When Closed blocks all orders when Clover API fails — changed to fail open so API downtime does not block customer orders
- Business hours parser only read first time slot per day — now reads all slots (supports split hours e.g. lunch + dinner)
- Quantity not sent correctly to Clover — atomic order now repeats line item N times for qty > 1 instead of always sending 1
- Customer name missing from Clover order note — logged-in users with empty WP profile now fall back to billing fields from checkout; guests always read from `billing_first_name` / `billing_last_name`

### Changed
- `printOrder(string $orderId, string $deviceId = '')` — new optional param; if device configured, sends `deviceRef` in payload
- Order note format: `"Special instructions: X"` → `"Note: X"` and added `"Customer: <name>."` prefix before note text
- Admin: moved "Prevent Orders When Closed" from Pricing tab to Store Hours tab
- Admin: moved all Business Hours Banner settings (Show Status Banner, Banner Position, Show Countdown, Test Business Hours) from Business Hours Banner tab to Store Hours tab
- Admin: removed Business Hours Banner tab from navigation (consolidated into Store Hours)

---

## [1.3.0] - 2026-05-12
### Added
- Tax rates leídos directo desde Clover API — se aplican como `taxRates` en cada line item
- Tax rates marcados como checked aparecen como cart fees en WC checkout (nombre y % desde Clover cache)
- Single product page: Add to Cart button muestra precio base + combined al seleccionar modifier
- Single product page: precio cambia a `<del>base</del> combinado` al seleccionar modifier (estilo sale price)
- Quick view modal: botón inicializado con precio base desde PHP en apertura
- Order Summary checkout: muestra "Base price: $X.XX" como item data cuando hay modifiers

### Fixed
- Modifier price double-count en Clover orders — precio de line item usaba `$item->get_subtotal()` (incluía modifier); ahora usa `_regular_price` meta directamente
- Tax rate percentage leído incorrectamente (`rate / 100000` daba porcentaje, no decimal) — corregido para cart fee calculation

### Removed
- Service Charge section completa del admin (campo ID, register_setting, add_settings_field, callback)
- `clover_service_charge_id` de `$tab_options['taxes']`
- `clover_service_charge_cached_percent` y `clover_service_charge_cached_name` options
- Auto-sync hook para `clover_service_charge_id` (llamaba API que retornaba 404/405)
- AJAX handler `clover_reload_service_charges` (sin botón en UI)
- `getServiceCharges()` y `applyServiceCharge()` de `OrderService.php`
- Dead `updateLineItem` tax loop en `class-orders.php`

### Changed
- Convenience Fee ahora se maneja 100% via Clover Tax Rates — un solo punto de configuración
- `clover_add_service_charge_fee`: lee name/% del `clover_tax_rates_cache` en vez de opciones separadas; procesa todos los rates checked incluyendo default (Sales Tax)
- `service_charge_callback` eliminado — reemplazado por nota en Tax Rates section

---

## [1.2.0] - 2026-05-01
### Added
- Line items enviados como ad-hoc (sin `item.id`) — permite override de `taxRates` en Clover
- Explicit `taxRates` array en cada line item usando IDs de rates marcados en Taxes and Fees tab
- Tax Rates grid en admin con checkbox por rate, badge "Default", botón Reload
- `clover_tax_rates_cache` — JSON de rates cacheado al guardar settings
- `clover_enabled_tax_rates` — array de IDs habilitados
- Tab discount (Discounts tab) aplicado a line item prices
- Settings audit log (`logs/settings-audit.log`) — registra fecha, usuario, valor anterior/nuevo por cada cambio en settings del plugin
- Store Hours tab — bloquea pedidos fuera de horario
- Logs tab con Enable/Disable y visualización en tiempo real

### Fixed
- Taxes no aparecían en recibo Clover — Clover ignora `taxRates` en catalog items; fix: enviar como ad-hoc
- 8.375% Sales Tax no aparecía en WC checkout — WC taxes deshabilitados; configurado tax rate universal
- Convenience Fee porcentaje incorrecto (mostraba 1% en vez de 10%) — escala `percentageDecimal / 10000` vs `percentage` directo

### Changed
- `createAtomicOrder` reemplazado por flujo: `createOrder` → `addBulkLineItems` → `addModificationToLineItem` por item
- Precio base de line item: `get_post_meta($id, '_regular_price', true)` en vez de `$item->get_subtotal()`

---

## [1.1.0] - 2026-04-15
### Added
- Auto Mark as Paid — crea payment record en Clover con tender seleccionado
- Auto Print — envía orden a impresora Clover via `/print_event`
- Payment Tender selector con botón Reload (carga tenders desde API)
- Employee selector con botón Reload
- Order Type Mapping — mapea métodos de envío WooCommerce a order types de Clover
- Default Order Type fallback
- Global Discount (Pricing tab) — descuento % aplicado a todos los productos
- Apply to Modifiers checkbox en Global Discount
- Discount tab — selecciona discount existente de Clover para aplicar a órdenes
- Quick View modal con selector de modifiers
- Multi-serving support en modifier groups

### Fixed
- Orden duplicada en Clover — plugin ahora ignora duplicados automáticamente

---

## [1.0.1] - 2026-03-20
### Fixed
- SKU vacío causaba omisión silenciosa del item — ahora logea warning
- Token/MerchID incorrectos no mostraban error claro — mejorado mensaje en Test Connection

---

## [1.0.0] - 2026-03-01
### Added
- Integración base WooCommerce → Clover
- Envío automático de orden cuando WC order cambia a "Processing"
- Soporte de modifiers via `_custom_modifier_data` en order items
- Admin panel con tabs: API, Orders, Pricing, Discounts, Taxes and Fees, Store Hours, Logs
- Test Connection button
- `OrderService.php` con métodos: `createOrder`, `addBulkLineItems`, `addModificationToLineItem`, `getTaxRates`, `getDiscounts`, `getTenders`, `getEmployees`, `getOrderTypes`, `printOrder`, `createPaymentForOrder`
- Configuración en Clover: SKU de producto = ID de item en Clover
- Documentación de configuración (`docs/configuracion-wpcloverSync.md`)
