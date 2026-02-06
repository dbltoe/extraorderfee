<?php
/**
 * Extra Order Fee Order Total Module
 *
 * @package   OrderTotal
 * @copyright Copyright 2003-2012 Zen Cart Development Team
 * @copyright Portions Copyright 2003 osCommerce
 * @license   http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version   $Id: ot_extraorderfee.php 6101 2012-10-19 10:30:22Z ajeh $
 * @deprecated This is a legacy module - consider migrating to modern Zen Cart/PSR-4 structure
 */

declare(strict_types=1);

class ot_extraorderfee
{
    public string $code = 'ot_extraorderfee';
    public string $title;
    public string $description;
    /** @var int|string */
    public $sort_order;
    /** @var array<int, array{title: string, text: string, value: float}> */
    public array $output = [];

    private array $manFees = [];
    private array $catFees = [];
    private array $prodFees = [];

    public function __construct()
    {
        $this->title       = MODULE_ORDER_TOTAL_EXTRAORDERFEE_TITLE ?? 'Extra Order Fee';
        $this->description = MODULE_ORDER_TOTAL_EXTRAORDERFEE_DESCRIPTION ?? 'Adds an extra fee to orders';
        $this->sort_order  = defined('MODULE_ORDER_TOTAL_EXTRAORDERFEE_SORT_ORDER') ? MODULE_ORDER_TOTAL_EXTRAORDERFEE_SORT_ORDER : 450;

        // Load fee maps
        $this->manFees  = $this->parseFeeConfig('MODULE_ORDER_TOTAL_EXTRAORDERFEE_MANUFACTURERS');
        $this->catFees  = $this->parseFeeConfig('MODULE_ORDER_TOTAL_EXTRAORDERFEE_CATEGORIES');
        $this->prodFees = $this->parseFeeConfig('MODULE_ORDER_TOTAL_EXTRAORDERFEE_PRODUCTS');
    }

    public function process(): void
    {
        global $order, $currencies, $db;

        $status = defined('MODULE_ORDER_TOTAL_EXTRAORDERFEE_STATUS') 
            ? MODULE_ORDER_TOTAL_EXTRAORDERFEE_STATUS 
            : 'false';

        if ($status !== 'true') {
            return;
        }

        // Zone check
        if (!$this->isZoneAllowed($order->delivery)) {
            return;
        }

        $fee = $this->calculateTotalFee();
        if ($fee <= 0) {
            return;
        }

        $taxClassId = (int)(MODULE_ORDER_TOTAL_EXTRAORDERFEE_TAX_CLASS ?? 0);

        $taxAddress = zen_get_tax_locations();

        $taxRate = zen_get_tax_rate(
            $taxClassId,
            $taxAddress['country_id'] ?? null,
            $taxAddress['zone_id']   ?? null
        );

        $taxAmount = zen_calculate_tax($fee, $taxRate);

        $taxDescription = zen_get_tax_description(
            $taxClassId,
            $taxAddress['country_id'] ?? null,
            $taxAddress['zone_id']   ?? null
        );

        // Update order totals
        $order->info['tax'] += $taxAmount;

        $order->info['tax_groups'][$taxDescription] =
            ($order->info['tax_groups'][$taxDescription] ?? 0) + $taxAmount;

        $totalToAdd = $fee + $taxAmount;
        $order->info['total'] += $totalToAdd;

        // Display logic - when prices include tax
        $displayAmount = (DISPLAY_PRICE_WITH_TAX === 'true')
            ? $fee + $taxAmount
            : $fee;

        $this->output[] = [
            'title' => $this->title . ':',
            'text'  => $currencies->format(
                $displayAmount,
                true,
                $order->info['currency']       ?? DEFAULT_CURRENCY,
                $order->info['currency_value'] ?? 1.0
            ),
            'value' => $displayAmount,
        ];
    }

    private function isZoneAllowed(array $deliveryAddress): bool
    {
        global $db;

        $zoneId = (int)(MODULE_ORDER_TOTAL_EXTRAORDERFEE_ZONE ?? 0);

        // No zone restriction
        if ($zoneId === 0) {
            return true;
        }

        $countryId      = (int)($deliveryAddress['country']['id'] ?? 0);
        $deliveryZoneId = (int)($deliveryAddress['zone_id'] ?? 0);

        $sql = "SELECT zone_id
                FROM " . TABLE_ZONES_TO_GEO_ZONES . "
                WHERE geo_zone_id    = :zoneId
                  AND zone_country_id = :countryId
                ORDER BY zone_id";

        $sql = $db->bindVars($sql, ':zoneId', $zoneId, 'integer');
        $sql = $db->bindVars($sql, ':countryId', $countryId, 'integer');
        $result = $db->Execute($sql);

        while (!$result->EOF) {
            $zone = (int)$result->fields['zone_id'];
            if ($zone === 0 || $zone === $deliveryZoneId) {
                return true;
            }
            $result->MoveNext();
        }

        return false;
    }

    private function calculateTotalFee(): float
    {
        if (!isset($_SESSION['cart']) || !is_object($_SESSION['cart'])) {
            return 0.0;
        }

        $totalFee = 0.0;

        foreach ($_SESSION['cart']->contents as $products_id => $product) {
            $productId = (int)zen_get_prid($products_id);
            $percent = $this->getApplicablePercent($productId);

            if ($percent > 0) {
                $itemSubtotal = (float)$product['final_price'] * (float)$product['quantity'];
                $totalFee += $itemSubtotal * ($percent / 100);
            }
        }

        return $totalFee;
    }

    private function getApplicablePercent(int $productId): float
    {
        global $db;

        // Priority: Product > Category (max if multiple) > Manufacturer

        // Check product-specific fee
        if (array_key_exists($productId, $this->prodFees)) {
            return $this->prodFees[$productId];
        }

        // Get product details: manufacturer and all linked categories
        $sql = "SELECT p.manufacturers_id, ptc.categories_id
                FROM " . TABLE_PRODUCTS . " p
                LEFT JOIN " . TABLE_PRODUCTS_TO_CATEGORIES . " ptc ON p.products_id = ptc.products_id
                WHERE p.products_id = :productId";
        $sql = $db->bindVars($sql, ':productId', $productId, 'integer');
        $result = $db->Execute($sql);

        if ($result->RecordCount() === 0) {
            return 0.0;
        }

        $manId = (int)$result->fields['manufacturers_id'];
        $categories = [];
        do {
            if ($result->fields['categories_id'] !== null) {
                $categories[] = (int)$result->fields['categories_id'];
            }
        } while ($result->MoveNext());

        $categories = array_unique($categories);

        // Check categories (take max percent if multiple matches)
        $maxCatPercent = 0.0;
        foreach ($categories as $catId) {
            if (array_key_exists($catId, $this->catFees)) {
                $maxCatPercent = max($maxCatPercent, $this->catFees[$catId]);
            }
        }
        if ($maxCatPercent > 0) {
            return $maxCatPercent;
        }

        // Check manufacturer
        if (array_key_exists($manId, $this->manFees)) {
            return $this->manFees[$manId];
        }

        return 0.0;
    }

    private function parseFeeConfig(string $key): array
    {
        $value = constant($key) ?? '';
        if (empty($value)) {
            return [];
        }

        $pairs = array_map('trim', explode(',', $value));
        $map = [];

        foreach ($pairs as $pair) {
            [$idStr, $percStr] = array_pad(array_map('trim', explode(':', $pair, 2)), 2, '');
            if (is_numeric($idStr) && is_numeric($percStr)) {
                $map[(int)$idStr] = (float)$percStr;
            }
        }

        return $map;
    }

    public function check(): int
    {
        global $db;

        /** @var int|null $check */
        static $check = null;

        if ($check === null) {
            $sql = "SELECT configuration_value
                    FROM " . TABLE_CONFIGURATION . "
                    WHERE configuration_key = 'MODULE_ORDER_TOTAL_EXTRAORDERFEE_STATUS'";

            $result = $db->Execute($sql);
            $check = $result->RecordCount();
        }

        return $check;
    }

    public function keys(): array
    {
        return [
            'MODULE_ORDER_TOTAL_EXTRAORDERFEE_STATUS',
            'MODULE_ORDER_TOTAL_EXTRAORDERFEE_SORT_ORDER',
            'MODULE_ORDER_TOTAL_EXTRAORDERFEE_TAX_CLASS',
            'MODULE_ORDER_TOTAL_EXTRAORDERFEE_ZONE',
            'MODULE_ORDER_TOTAL_EXTRAORDERFEE_MANUFACTURERS',
            'MODULE_ORDER_TOTAL_EXTRAORDERFEE_CATEGORIES',
            'MODULE_ORDER_TOTAL_EXTRAORDERFEE_PRODUCTS',
        ];
    }

    public function install(): void
    {
        global $db;

        $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) VALUES ('Enable Extra Order Fee Module', 'MODULE_ORDER_TOTAL_EXTRAORDERFEE_STATUS', 'true', 'Do you want to enable the Extra Order Fee module?', '6', '1', 'zen_cfg_select_option(array(\'true\', \'false\'), ', now())");

        $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) VALUES ('Sort Order', 'MODULE_ORDER_TOTAL_EXTRAORDERFEE_SORT_ORDER', '450', 'Sort order of display. Lowest is displayed first.', '6', '2', now())");

        $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, set_function, date_added) VALUES ('Tax Class', 'MODULE_ORDER_TOTAL_EXTRAORDERFEE_TAX_CLASS', '0', 'Use the following tax class on the extra fee.', '6', '3', 'zen_get_tax_class_title', 'zen_cfg_pull_down_tax_classes(', now())");

        $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, set_function, date_added) VALUES ('Shipping Zone', 'MODULE_ORDER_TOTAL_EXTRAORDERFEE_ZONE', '0', 'If a zone is chosen, only enable this extra fee for that zone.', '6', '4', 'zen_get_zone_class_title', 'zen_cfg_pull_down_zone_classes(', now())");

        $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) VALUES ('Apply Fee to Selected Manufacturers (ID:percentage, comma separated, leave blank for none)', 'MODULE_ORDER_TOTAL_EXTRAORDERFEE_MANUFACTURERS', '', 'Percentage fee for products from these manufacturers. Example: 5:8,9:20', '6', '5', now())");

        $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) VALUES ('Apply Fee to Selected Categories (ID:percentage, comma separated, leave blank for none)', 'MODULE_ORDER_TOTAL_EXTRAORDERFEE_CATEGORIES', '', 'Percentage fee for products in these categories (checks all linked categories). Example: 3:10,15:12', '6', '6', now())");

        $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) VALUES ('Apply Fee to Selected Products (ID:percentage, comma separated, leave blank for none)', 'MODULE_ORDER_TOTAL_EXTRAORDERFEE_PRODUCTS', '', 'Percentage fee for these specific products. Example: 123:5,456:15', '6', '7', now())");
    }

    public function remove(): void
    {
        global $db;

        $db->Execute("DELETE FROM " . TABLE_CONFIGURATION . "
                      WHERE configuration_key LIKE 'MODULE\\_ORDER\\_TOTAL\\_EXTRAORDERFEE\\_%'");
    }
}
