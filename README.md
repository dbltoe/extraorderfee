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
Extra Order Fee is for addition Order Fee with or without a Zone and is based on an Amount or Percentage charge. It is also selectable by Manufacturer and/or Category.  After being loaded, your settings will appear in Admin > Modules > Order Total > Extra Order Fee.

---

## Installation Instructions:

1. Backup, Backup, Backup!
2. Unzip the file you have received.
3. Copy files into the same structure as your site.

/includes/languages/english/modules/order_total/ot_extraorderfee.php
/includes/modules/order_total/ot_extraorderfee.php

## Upgrading from previous versions:

1. Upload the new ot_extraorderfee.php file
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
