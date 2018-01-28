<?php

namespace BigBridge\ProductImport\Model\Resource;

use BigBridge\ProductImport\Api\Data\BundleProduct;
use BigBridge\ProductImport\Api\Data\BundleProductStoreView;
use BigBridge\ProductImport\Api\Data\DownloadableProduct;
use BigBridge\ProductImport\Api\Data\DownloadableProductStoreView;
use BigBridge\ProductImport\Api\Data\Product;
use BigBridge\ProductImport\Api\Data\ProductStoreView;
use BigBridge\ProductImport\Model\Db\Magento2DbConnection;
use BigBridge\ProductImport\Api\ImportConfig;
use BigBridge\ProductImport\Model\Resource\Resolver\ReferenceResolver;
use BigBridge\ProductImport\Model\Resource\Resolver\UrlKeyGenerator;
use BigBridge\ProductImport\Model\Resource\Serialize\ValueSerializer;
use BigBridge\ProductImport\Model\Resource\Storage\ImageStorage;
use BigBridge\ProductImport\Model\Resource\Storage\LinkedProductStorage;
use BigBridge\ProductImport\Model\Resource\Storage\ProductEntityStorage;
use BigBridge\ProductImport\Model\Resource\Storage\StockItemStorage;
use BigBridge\ProductImport\Model\Resource\Storage\TierPriceStorage;
use BigBridge\ProductImport\Model\Resource\Storage\UrlRewriteStorage;
use BigBridge\ProductImport\Model\Resource\Validation\Validator;
use Exception;

/**
 * @author Patrick van Bergen
 */
abstract class ProductStorage
{
    /** @var  Magento2DbConnection */
    protected $db;

    /** @var  MetaData */
    protected $metaData;

    /** @var Validator */
    protected $validator;

    /** @var  ReferenceResolver */
    protected $referenceResolver;

    /** @var UrlKeyGenerator */
    protected $urlKeyGenerator;

    /** @var UrlRewriteStorage */
    protected $urlRewriteStorage;

    /** @var ImageStorage */
    protected $imageStorage;

    /** @var LinkedProductStorage */
    protected $linkedProductStorage;

    /** @var ProductEntityStorage */
    protected $productEntityStorage;

    /** @var TierPriceStorage */
    protected $tierPriceStorage;

    /** @var StockItemStorage */
    protected $stockItemStorage;

    public function __construct(
        Magento2DbConnection $db,
        MetaData $metaData,
        Validator $validator,
        ReferenceResolver $referenceResolver,
        UrlKeyGenerator $urlKeyGenerator,
        UrlRewriteStorage $urlRewriteStorage,
        ProductEntityStorage $productEntityStorage,
        ImageStorage $imageStorage,
        LinkedProductStorage $linkedProductStorage,
        TierPriceStorage $tierPriceStorage,
        StockItemStorage $stockItemStorage)
    {
        $this->db = $db;
        $this->metaData = $metaData;
        $this->validator = $validator;
        $this->referenceResolver = $referenceResolver;
        $this->urlKeyGenerator = $urlKeyGenerator;
        $this->urlRewriteStorage = $urlRewriteStorage;
        $this->productEntityStorage = $productEntityStorage;
        $this->imageStorage = $imageStorage;
        $this->linkedProductStorage = $linkedProductStorage;
        $this->tierPriceStorage = $tierPriceStorage;
        $this->stockItemStorage = $stockItemStorage;
    }

    /**
     * @param Product[] $insertProducts
     * @param Product[] $updateProducts
     */
    public abstract function performTypeSpecificStorage(array $insertProducts, array $updateProducts);

    /**
     * @param Product[] $products Sku-indexed products of various product types
     * @param ImportConfig $config
     * @param ValueSerializer $valueSerializer
     * @param bool $useCallbacks
     * @throws Exception
     */
    public function storeProducts(array $products, ImportConfig $config, ValueSerializer $valueSerializer, bool $useCallbacks)
    {
        if (empty($products)) {
            return;
        }

        // connect store view to product
        $this->setupStoreViewWiring($products);

        // collect skus
        $skus = [];
        foreach ($products as $product) {
            $skus[] = $product->getSku();
        }

        // find existing products ids from their skus
        $sku2id = $this->productEntityStorage->getExistingSkus($skus);

        // separate new products from existing products and assign id
        $insertProducts = $updateProducts = $productsWithId = [];
        foreach ($products as $product) {

            if ($product->id) {
                $updateProducts[] = $product;
                $productsWithId[] = $product;
            } else if (array_key_exists($product->getSku(), $sku2id)) {
                $product->id = $sku2id[$product->getSku()];
                $updateProducts[] = $product;
            } else {
                $insertProducts[] = $product;
            }
        }

        // check if the pre-specified ids exist
        $this->productEntityStorage->checkIfIdsExist($productsWithId);

        // set default values for new products
        $this->setDefaultValues($insertProducts);

        // replace Reference(s) with ids, changes $product->errors
        $this->referenceResolver->resolveIds($products, $config);

        // create url keys based on name and id
        // changes $product->errors
        $this->urlKeyGenerator->createUrlKeysForNewProducts($insertProducts, $config->urlKeyScheme, $config->duplicateUrlKeyStrategy);
        $this->urlKeyGenerator->createUrlKeysForExistingProducts($updateProducts, $config->urlKeyScheme, $config->duplicateUrlKeyStrategy);

        // create an array of products without errors
        $validProducts = $this->collectValidProducts($products);

        // in a "dry run" no actual imports to the database are done
        if (!$config->dryRun) {

            // store the products in the database
            $this->saveProducts($validProducts, $valueSerializer);
        }

        // call user defined functions to let them process the results
        if ($useCallbacks) {
            foreach ($config->resultCallbacks as $callback) {
                foreach ($products as $product) {
                    call_user_func($callback, $product);
                }
            }
        }

        // disconnect store view to product
        // this is done to remove reference cycles that trouble garbage collection
        $this->tearDownStoreViewWiring($products);
    }

    /**
     * Connect product to store view
     *
     * @param Product[] $products
     */
    protected function setupStoreViewWiring(array $products)
    {
        foreach ($products as $product) {
            foreach ($product->getStoreViews() as $storeView) {
                $storeView->parent = $product;
            }
        }
    }

    /**
     * Help the garbage collector by removing cyclic dependencies
     *
     * @param Product[] $products
     */
    protected function tearDownStoreViewWiring(array $products)
    {
        foreach ($products as $product) {
            foreach ($product->getStoreViews() as $storeView) {
                $storeView->parent = null;
            }
        }
    }

    /**
     * @param array $products
     * @return array
     */
    public function collectValidProducts(array $products): array
    {
        $validProducts = [];

        foreach ($products as $product) {

            // checks all attributes, changes $product->errors
            $this->validator->validate($product);

            if (!$product->isOk()) {
                continue;
            }

            // collect valid products
            $validProducts[] = $product;
        }
        return $validProducts;
    }

    /**
     * @param Product[] $insertProducts
     */
    protected function setDefaultValues(array $insertProducts)
    {
        foreach ($insertProducts as $product) {

            // attribute set: Default
            if ($product->getAttributeSetId() === null) {
                $product->setAttributeSetByName("Default");
            }

            $global = $product->global();
            $attributes = $global->getAttributes();

            // visibility: both
            if (!array_key_exists(ProductStoreView::ATTR_VISIBILITY, $attributes)) {
                $global->setVisibility(ProductStoreView::VISIBILITY_BOTH);
            }
            // status: disabled
            if (!array_key_exists(ProductStoreView::ATTR_STATUS, $attributes)) {
                $global->setStatus(ProductStoreView::STATUS_DISABLED);
            }
            // tax class: Taxable Goods
            if (!array_key_exists(ProductStoreView::ATTR_TAX_CLASS_ID, $attributes)) {
                $global->setTaxClassName("Taxable Goods");
            }

            if ($global instanceof DownloadableProductStoreView) {
                if (!array_key_exists(DownloadableProductStoreView::ATTR_LINKS_PURCHASED_SEPARATELY, $attributes)) {
                    $global->setLinksPurchasedSeparately(false);
                }
                if (!array_key_exists(DownloadableProductStoreView::ATTR_LINKS_TITLE, $attributes)) {
                    $global->setLinksTitle("Links");
                }
                if (!array_key_exists(DownloadableProductStoreView::ATTR_SAMPLES_TITLE, $attributes)) {
                    $global->setSamplesTitle("Samples");
                }
            } elseif ($global instanceof BundleProductStoreView) {
                if (!array_key_exists(BundleProductStoreView::ATTR_PRICE_TYPE, $attributes)) {
                    $global->setPriceType(BundleProductStoreView::PRICE_TYPE_DYNAMIC);
                }
                if (!array_key_exists(BundleProductStoreView::ATTR_PRICE_VIEW, $attributes)) {
                    $global->setPriceView(BundleProductStoreView::PRICE_VIEW_PRICE_RANGE);
                }
                if (!array_key_exists(BundleProductStoreView::ATTR_SKU_TYPE, $attributes)) {
                    $global->setSkuType(BundleProductStoreView::SKU_TYPE_DYNAMIC);
                }
                if (!array_key_exists(BundleProductStoreView::ATTR_WEIGHT_TYPE, $attributes)) {
                    $global->setWeightType(BundleProductStoreView::WEIGHT_TYPE_DYNAMIC);
                }
                if (!array_key_exists(BundleProductStoreView::ATTR_SHIPMENT_TYPE, $attributes)) {
                    $global->setShipmentType(BundleProductStoreView::SHIPMENT_TYPE_TOGETHER);
                }
            }
        }
    }

    /**
     * @param Product[] $validProducts
     * @param ValueSerializer $valueSerializer
     * @throws Exception
     */
    protected function saveProducts(array $validProducts, ValueSerializer $valueSerializer)
    {
        $validUpdateProducts = $validInsertProducts = [];
        $productsByAttribute = [];
        $productsWithCategories = [];
        $productsWithWebsites = [];

        foreach ($validProducts as $product) {

            // collect valid new and existing products
            if ($product->id !== null) {
                $validUpdateProducts[] = $product;
            } else {
                $validInsertProducts[] = $product;
            }

            if ($product->getCategoryIds() !== []) {
                $productsWithCategories[] = $product;
            }

            if ($product->getWebsiteIds() !== []) {
                $productsWithWebsites[] = $product;
            }

            foreach ($product->getStoreViews() as $storeView) {
                foreach ($storeView->getAttributes() as $key => $value) {
                    $productsByAttribute[$key][] = $storeView;
                }
            }
        }

        $this->db->execute("START TRANSACTION");

        // existing values must be queried before the product is inserted or updated
        $existingValues = $this->getExistingProductValues($validUpdateProducts);

        try {

            $this->productEntityStorage->insertMainTable($validInsertProducts);
            $this->productEntityStorage->updateMainTable($validUpdateProducts);

            foreach ($productsByAttribute as $eavAttribute => $products) {
                $this->insertEavAttribute($products, $eavAttribute);
            }

            $this->insertCategoryIds($productsWithCategories);
            $this->insertWebsiteIds($productsWithWebsites);

            $this->stockItemStorage->storeStockItems($validProducts);

            $this->linkedProductStorage->insertLinkedProducts($validInsertProducts);
            $this->linkedProductStorage->updateLinkedProducts($validUpdateProducts);

            $this->imageStorage->storeProductImages($validProducts);

            $this->tierPriceStorage->insertTierPrices($validInsertProducts);
            $this->tierPriceStorage->updateTierPrices($validUpdateProducts);

            // url_rewrite (must be done after url_key and category_id)
            $this->urlRewriteStorage->insertRewrites($validInsertProducts, $valueSerializer);
            $this->urlRewriteStorage->updateRewrites($validUpdateProducts, $existingValues, $valueSerializer);

            $this->performTypeSpecificStorage($validInsertProducts, $validUpdateProducts);

            $this->db->execute("COMMIT");

        } catch (Exception $e) {

            // rollback the transaction
            try { $this->db->execute("ROLLBACK"); } catch (Exception $f) {}

            // let the application handle the exception
            throw $e;
        }
    }

    protected function getExistingProductValues(array $products)
    {
        if (empty($products)) {
            return [];
        }

        $productIds = array_column($products, 'id');

        $attributeId = $this->metaData->productEavAttributeInfo['url_key']->attributeId;

        $existingData = $this->db->fetchAllAssoc("
            SELECT URL_KEY.`entity_id` as product_id, URL_KEY.`value` AS url_key, GROUP_CONCAT(PG.`category_id` SEPARATOR ',') as category_ids, URL_KEY.`store_id`
            FROM `{$this->metaData->productEntityTable}_varchar` URL_KEY
            LEFT JOIN `{$this->metaData->urlRewriteProductCategoryTable}` PG ON PG.`product_id` = URL_KEY.`entity_id`
            WHERE 
                URL_KEY.`attribute_id` = $attributeId AND
                URL_KEY.`entity_id` IN (" . implode(', ', $productIds) . ")
            GROUP BY URL_KEY.`entity_id`, URL_KEY.`store_id` 
        ");

        $data = [];
        foreach ($existingData as $existingDatum) {
            $productId = $existingDatum['product_id'];
            $storeId = $existingDatum['store_id'];
            $categoryIds = is_null($existingDatum['category_ids']) ? [] : explode(',', $existingDatum['category_ids']);
            $urlKey = $existingDatum['url_key'];
            $data[$storeId][$productId] = ['url_key' => $urlKey, 'category_ids' => $categoryIds];
        }

        return $data;
    }

    /**
     * @param ProductStoreView[] $storeViews
     * @param string $eavAttribute
     */
    protected function insertEavAttribute(array $storeViews, string $eavAttribute)
    {
        $attributeInfo = $this->metaData->productEavAttributeInfo[$eavAttribute];
        $tableName = $attributeInfo->tableName;
        $attributeId = $attributeInfo->attributeId;

        $values = [];
        foreach ($storeViews as $storeView) {

            $entityId = $storeView->parent->id;
            $value = $this->db->quote($storeView->getAttribute($eavAttribute));
            $storeViewId = $storeView->getStoreViewId();
            $values[] = "({$entityId},{$attributeId},{$storeViewId},{$value})";
        }

        $sql = "INSERT INTO `{$tableName}` (`entity_id`, `attribute_id`, `store_id`, `value`)" .
            " VALUES " . implode(', ', $values) .
            " ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";

        $this->db->execute($sql);
    }

    /**
     * @param Product[] $products
     */
    protected function insertCategoryIds(array $products)
    {
        $values = [];

        foreach ($products as $product) {
            foreach ($product->getCategoryIds() as $categoryId) {
                $values[] = "({$categoryId}, {$product->id})";
            }
        }

        if (count($values) > 0) {

            // IGNORE serves two purposes:
            // 1. do not fail if the product-category link already existed
            // 2. do not fail if the category does not exist

            $sql = "
                INSERT IGNORE INTO `{$this->metaData->categoryProductTable}` (`category_id`, `product_id`) 
                VALUES " . implode(', ', $values);

            $this->db->execute($sql);
        }
    }

    /**
     * @param Product[] $products
     */
    protected function insertWebsiteIds(array $products)
    {
        $values = [];

        foreach ($products as $product) {
            foreach ($product->getWebsiteIds() as $websiteId) {
                $values[] = "({$product->id}, {$websiteId})";
            }
        }

        if (count($values) > 0) {

            // IGNORE serves two purposes:
            // 1. do not fail if the product-website link already existed
            // 2. do not fail if the website does not exist

            $sql = "
                INSERT IGNORE INTO `{$this->metaData->productWebsiteTable}` (`product_id`, `website_id`) 
                VALUES " . implode(', ', $values);

            $this->db->execute($sql);
        }
    }
}