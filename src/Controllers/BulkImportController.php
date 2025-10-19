<?php

namespace PriceGrabber\Controllers;

use PriceGrabber\Models\Product;
use PriceGrabber\Core\Logger;

class BulkImportController
{
    private $productModel;

    public function __construct()
    {
        $this->productModel = new Product();
    }

    public function import($data)
    {
        $lines = explode("\n", trim($data));
        $results = [
            'success' => 0,
            'updated' => 0,
            'failed' => 0,
            'errors' => []
        ];

        Logger::info('Starting bulk import', ['total_lines' => count($lines)]);

        foreach ($lines as $lineNum => $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $parts = explode("\t", $line);

            // Expected format: product_id \t parent_id \t sku \t ean \t site \t site_product_id \t price \t uvp \t site_status \t product_priority \t url \t name \t description
            if (count($parts) < 11) {
                $results['failed']++;
                $error = "Line " . ($lineNum + 1) . ": Not enough columns (expected at least 11: product_id, parent_id, sku, ean, site, site_product_id, price, uvp, site_status, product_priority, url)";
                $results['errors'][] = $error;
                Logger::warning('Import line failed', ['line' => $lineNum + 1, 'error' => 'Not enough columns']);
                continue;
            }

            $productData = [
                'product_id' => trim($parts[0]),
                'parent_id' => !empty(trim($parts[1])) ? trim($parts[1]) : null,
                'sku' => !empty(trim($parts[2])) ? trim($parts[2]) : null,
                'ean' => !empty(trim($parts[3])) ? trim($parts[3]) : null,
                'site' => !empty(trim($parts[4])) ? trim($parts[4]) : null,
                'site_product_id' => !empty(trim($parts[5])) ? trim($parts[5]) : null,
                'price' => !empty(trim($parts[6])) ? (float)trim($parts[6]) : null,
                'uvp' => !empty(trim($parts[7])) ? (float)trim($parts[7]) : null,
                'site_status' => !empty(trim($parts[8])) ? trim($parts[8]) : null,
                'product_priority' => !empty(trim($parts[9])) ? trim($parts[9]) : 'unknown',
                'url' => trim($parts[10]),
                'name' => isset($parts[11]) && !empty(trim($parts[11])) ? trim($parts[11]) : null,
                'description' => isset($parts[12]) && !empty(trim($parts[12])) ? trim($parts[12]) : null,
            ];

            // Validate required fields
            if (empty($productData['product_id'])) {
                $results['failed']++;
                $error = "Line " . ($lineNum + 1) . ": product_id is required";
                $results['errors'][] = $error;
                Logger::warning('Import line failed', ['line' => $lineNum + 1, 'error' => 'Missing product_id']);
                continue;
            }

            if (empty($productData['url'])) {
                $results['failed']++;
                $error = "Line " . ($lineNum + 1) . ": url is required";
                $results['errors'][] = $error;
                Logger::warning('Import line failed', ['line' => $lineNum + 1, 'error' => 'Missing url']);
                continue;
            }

            try {
                // Check if product exists by product_id
                $existingProduct = $this->productModel->findByProductId($productData['product_id']);

                if ($existingProduct) {
                    // Update existing product
                    $this->productModel->update($productData['product_id'], $productData);
                    $results['updated']++;
                } else {
                    // Create new product
                    $this->productModel->create($productData);
                    $results['success']++;
                }
            } catch (\Exception $e) {
                $results['failed']++;
                $error = "Line " . ($lineNum + 1) . ": " . $e->getMessage();
                $results['errors'][] = $error;
                Logger::error('Import line failed', [
                    'line' => $lineNum + 1,
                    'product_id' => $productData['product_id'] ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
            }
        }

        Logger::info('Bulk import completed', [
            'success' => $results['success'],
            'updated' => $results['updated'],
            'failed' => $results['failed']
        ]);

        return $results;
    }

    public function getTemplate()
    {
        return "product_id\tparent_id\tsku\tean\tsite\tsite_product_id\tprice\tuvp\tsite_status\tproduct_priority\turl\tname\tdescription";
    }

    public function getExampleRow()
    {
        return "PROD001\t\tSKU123\t1234567890123\tamazon\tB08X123\t29.99\t39.99\tactive\twhite\thttps://example.com/product\tExample Product\tProduct description";
    }
}
