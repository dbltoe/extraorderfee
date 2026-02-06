# Extra Order Fee
## Version v2.1.0

---
Released under the GNU General Public License
See license.txt file.
---

Original Author: Ajeh
Updated by dbltoe
Donations welcome at http://www.zen-cart.com/content.php?6-donate
No warranties expressed or implied; use at your own risk.

---
## Overview:
The **Extra Order Fee** module is a flexible **order-total addon** for Zen Cart that lets you add a surcharge (fee) to customer orders during checkout. It supports **percentage-based fees** (calculated on item subtotals) and applies them selectively based on:

- Specific **manufacturers** (e.g., higher fees for certain brands/suppliers),
- Specific **categories** (including all linked categories a product belongs to),
- Specific **products** (direct per-product overrides).

Key features include:
- Configurable via **Admin > Modules > Order Total > Extra Order Fee**.
- Fees are **percentage-only** (e.g., `5:8,9:20` format for "Manufacturer ID 5 = 8%, ID 9 = 20%").
- **Priority logic**: Product-specific fee > highest matching category fee > manufacturer fee (if multiple apply to one item, the most specific wins).
- Optional restrictions by **shipping zone** (geo-based) and **tax class** (for applying the correct sales tax to the fee).
- **No flat/fixed fee** option — all fees are percentage-based for better accuracy on varying order totals.
- Tested with Zen Cart 2.2.0-alpha and PHP thru 8.4 (uses modern array language files and strict constant checks).
- As always, install at your own risk.

After installation/upgrade, configure your fees in the module settings. The fee will automatically appear as a line item on the checkout confirmation page and be added to the order total (with tax if configured).

This makes it ideal for scenarios like supplier-mandated handling fees, category-specific surcharges, or premium product markups — all without affecting unrelated items.

---
## Changes in 2.1.0:
The overall flat rate was removed, and now the extra order fees can be set for a specific Manufacturer, Category, and/or Product ID.

---
## Installation Instructions:

1. Backup, Backup, Backup!
2. Unzip the file you have received.
3. Copy files into the same structure as your site.

`/includes/languages/english/modules/order_total/ot_extraorderfee.php`<br>
`/includes/modules/order_total/ot_extraorderfee.php`

## Upgrading from previous versions:

1. Upload the new files overwriting the files from version 2.00.0
2. In Admin → Modules → Order Totals → Extra Order Fee:
   - Click the module row
   - In the sidebar, click "Remove"
   - Then click "Install" to re-add with the new configuration options
3. Re-configure the new per-manufacturer/category/product percentage settings (old flat fee setting will be gone)


## No Core files are overwritten with this module.

## The file will create new options in the database to select Manufacturers, Categories, etc.

=============================

The Zone is based on the Shipping delivery address.

This can be changed to the Payment billing address by changing references to:
$order->delivery

to read:
$order->billing

If using the Percentage charge, this is based on the Order Subtotal.

This can be changed to the Order Total by changing references to:
$order->info['subtotal']

to read:
$order->info['total']

For the selection of Manufacturers, Categories, etc.
If left blank, the fee applies to all orders.  To apply, enter the ID and use a comma-delimited setting for multiples (5,12,64).
