<?php
/**
 * Extra Order Fee Order Total Module
 *
 * @package   OrderTotal
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @copyright Portions Copyright 2003 osCommerce
 * @license   http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version   $Id: ot_extraorderfee.php 6101 2026-1-17 11:30:22Z dbltoe $
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

    public function __construct()
    {
        $this->title       = MODULE_ORDER_TOTAL_EXTRAORDERFEE_TITLE;
        $this->description = MODULE_ORDER_TOTAL_EXTRAORDERFEE_DESCRIPTION;
        $this->sort_order = (defined('MODULE_ORDER_TOTAL_EXTRAORDERFEE_SORT_ORDER')) ? MODULE_ORDER_TOTAL_EXTRAORDERFEE_SORT_ORDER : null;
    }

    public function process(): void
    {
        global $order, $currencies, $db;

        if (!defined('MODULE_ORDER_TOTAL_EXTRAORDERFEE_STATUS') ||
            MODULE_ORDER_TOTAL_EXTRAORDERFEE_STATUS !== 'true') {
            return;
        }

        // Zone check
        if (!$this->isZoneAllowed($order->delivery)) {
            return;
        }

        // Check manufacturer/category restriction
        if (!$this->shouldApplyToCart()) {
            return;
        }

        $fee = $this->calculateFee((float)($order->info['subtotal'] ?? 0.0));
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

    private function calculateFee(float $subtotal): float
    {
        $feeSetting = MODULE_ORDER_TOTAL_EXTRAORDERFEE_FEE ?? '0';

        if (str_ends_with($feeSetting, '%')) {
            $percentage = (float)trim($feeSetting, '% ');
            return $subtotal * ($percentage / 100);
        }

        return (float)$feeSetting;
    }

    /**
     * Check if the current cart contains at least one product from the allowed
     * manufacturers or categories. If both are empty → always apply.
     */
    private function shouldApplyToCart(): bool
    {
        global $db;

        $allowedManufacturers = $this->getArrayFromConfig('MODULE_ORDER_TOTAL_EXTRAORDERFEE_MANUFACTURERS');
        $allowedCategories    = $this->getArrayFromConfig('MODULE_ORDER_TOTAL_EXTRAORDERFEE_CATEGORIES');

        // If neither restriction is set → apply to all carts
        if (empty($allowedManufacturers) && empty($allowedCategories)) {
            return true;
        }

        foreach ($_SESSION['cart']->contents as $products_id => $product) {
            $productId = (int)zen_get_prid($products_id); // Handle attributes if needed

            // Get manufacturer
            $manSql = "SELECT manufacturers_id
                       FROM " . TABLE_PRODUCTS . "
                       WHERE products_id = :productId";
            $manSql =  $db->bindVars($manSql, ':productId', $productId, 'integer');;
            $manResult = $db->Execute($manSql, 1);
            $manId = ($manResult->RecordCount() > 0) ? (int)$manResult->fields['manufacturers_id'] : 0;

            // Check manufacturer match
            if (!empty($allowedManufacturers) && in_array($manId, $allowedManufacturers, true)) {
                return true;
            }

            // Get all linked categories for this product
            if (!empty($allowedCategories)) {
                $catSql = "SELECT categories_id
                           FROM " . TABLE_PRODUCTS_TO_CATEGORIES . "
                           WHERE products_id = :productId";
                $catSql =  $db->bindVars($catSql, ':productId', $productId, 'integer');;
                $catResult = $db->Execute($catSql, 1);

                while (!$catResult->EOF) {
                    $catId = (int)$catResult->fields['categories_id'];
                    if (in_array($catId, $allowedCategories, true)) {
                        return true;
                    }
                    $catResult->MoveNext();
                }
            }
        }

        // No matching product found → do NOT apply fee
        return false;
    }

    /**
     * Helper: Convert comma-separated config string to array of integers
     */
    private function getArrayFromConfig(string $key): array
    {
        $value = constant($key) ?? '';
        if (empty($value)) {
            return [];
        }

        $ids = array_map('trim', explode(',', $value));
        $ids = array_filter($ids, 'is_numeric');
        $ids = array_map('intval', $ids);

        return array_unique($ids);
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
            'MODULE_ORDER_TOTAL_EXTRAORDERFEE_FEE',
            'MODULE_ORDER_TOTAL_EXTRAORDERFEE_TAX_CLASS',
            'MODULE_ORDER_TOTAL_EXTRAORDERFEE_ZONE',
            'MODULE_ORDER_TOTAL_EXTRAORDERFEE_MANUFACTURERS',
            'MODULE_ORDER_TOTAL_EXTRAORDERFEE_CATEGORIES',
        ];
    }

    public function install(): void
    {
        global $db;

        $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) VALUES ('Enable Extra Order Fee Module', 'MODULE_ORDER_TOTAL_EXTRAORDERFEE_STATUS', 'true', 'Do you want to enable the Extra Order Fee module?', '6', '1', 'zen_cfg_select_option(array(\'true\', \'false\'), ', now())");

        $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) VALUES ('Sort Order', 'MODULE_ORDER_TOTAL_EXTRAORDERFEE_SORT_ORDER', '450', 'Sort order of display. Lowest is displayed first.', '6', '2', now())");

        $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) VALUES ('Extra Fee', 'MODULE_ORDER_TOTAL_EXTRAORDERFEE_FEE', '5', 'Flat fee or percentage (e.g., 5 or 10%).', '6', '3', now())");

        $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, set_function, date_added) VALUES ('Tax Class', 'MODULE_ORDER_TOTAL_EXTRAORDERFEE_TAX_CLASS', '0', 'Use the following tax class on the extra fee.', '6', '4', 'zen_get_tax_class_title', 'zen_cfg_pull_down_tax_classes(', now())");

        $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, set_function, date_added) VALUES ('Shipping Zone', 'MODULE_ORDER_TOTAL_EXTRAORDERFEE_ZONE', '0', 'If a zone is chosen, only enable this extra fee for that zone.', '6', '5', 'zen_get_zone_class_title', 'zen_cfg_pull_down_zone_classes(', now())");

        $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) VALUES ('Apply Fee Only to Selected Manufacturers (comma separated IDs, leave blank for all)', 'MODULE_ORDER_TOTAL_EXTRAORDERFEE_MANUFACTURERS', '', 'Fee applies only if cart contains product(s) from these manufacturer IDs. Example: 5,12,27', '6', '6', now())");

        $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) VALUES ('Apply Fee Only to Selected Categories (comma separated IDs, leave blank for all)', 'MODULE_ORDER_TOTAL_EXTRAORDERFEE_CATEGORIES', '', 'Fee applies only if cart contains product(s) from these category IDs (checks all linked categories). Example: 3,15,22', '6', '7', now())");
    }

    public function remove(): void
    {
        global $db;

        $db->Execute("DELETE FROM " . TABLE_CONFIGURATION . "
                      WHERE configuration_key LIKE 'MODULE\\_ORDER\\_TOTAL\\_EXTRAORDERFEE\\_%'");
    }

}

