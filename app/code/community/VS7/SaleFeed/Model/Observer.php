<?php

class VS7_SaleFeed_Model_Observer
{
    private
        $_filePointer,
        $_storeId = 1,
        $_productCollection,
        $_productCategories = array(),
        $_productCategoriesUnique = array(),
        $_allCategories = array(),
        $_finalCategories = array(),
        $_productsTmpFile,
        $_categoriesTmpFile,
        $_limitAttributeSets = array(66, 70, 46, 69),
        $_baseUrl,
        $_step = 1000,
        $_rootCategoryId,
        $_notFound = array();

    public function generateFeed()
    {
        if (empty(Mage::getStoreConfig('vs7_salefeed/general/active'))) {
            return;
        }

        $this->_rootCategoryId = Mage::app()->getStore($this->_storeId)->getRootCategoryId();

        $this->_baseUrl = Mage::app()->getStore($this->_storeId)->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK);

        $feedPath = Mage::getBaseDir('media') . DS . 'salefeed' . DS . 'products-' . Mage::getStoreConfig('vs7_salefeed/general/filename') . '.yml';
        if (!file_exists(dirname($feedPath))) {
            mkdir(dirname($feedPath), 0777, true);
        }
        if (
            (file_exists($feedPath) && !is_writable($feedPath))
            || (!file_exists($feedPath) && !is_writable(dirname($feedPath)))
        ) {
            Mage::throwException($feedPath . ' is not writable');
        }

        $this->_finalCategories = $this->_getFinalCategories();

//        $this->_allCategories = $this->_getAllCategories();

        $this->_filePointer = fopen($this->_getTempProductsPath(), 'w');
        $this->_writeProductsData();
        $this->_row('</shop>');
        $this->_row('</yml_catalog>');
        fclose($this->_filePointer);

        $this->_filePointer = fopen($this->_getTempCategoriesPath(), 'w');
        $this->_row('<?xml version="1.0" encoding="utf-8"?>');
        $this->_row('<yml_catalog date="' . date('Y-m-d H:i') . '">');
        $this->_row('<shop>');
        $this->_row('<name>' . Mage::getStoreConfig('vs7_salefeed/general/store_name') . '</name>');
        $this->_row('<company>' . Mage::getStoreConfig('vs7_salefeed/general/company_name') . '</company>');
        $this->_row('<url>' . $this->_baseUrl . '</url>');
        $this->_row('<currencies><currency id="RUR" rate="1"/></currencies>');
        $this->_writeCategoriesData();
        fclose($this->_filePointer);

        $context = stream_context_create();
        $this->_filePointer = fopen($this->_getTempProductsPath(), 'r', 1, $context);
        file_put_contents($this->_getTempCategoriesPath(), $this->_filePointer, FILE_APPEND);
        fclose($this->_filePointer);

        unlink($this->_getTempProductsPath());
        rename($this->_getTempCategoriesPath(), $feedPath);
    }

    private function _row($text)
    {
        fwrite($this->_filePointer, $text . "\r\n");
    }

    private function _writeCategoriesData()
    {
        $categoryCollection = Mage::getModel('catalog/category')
            ->getCollection()
            ->setStoreId($this->_storeId)
            ->addFieldToFilter('is_active', 1)
            ->addAttributeToFilter('path', array('like' => "1/{$this->_rootCategoryId}/%"))
            ->addAttributeToFilter('entity_id', array('in' => $this->_productCategoriesUnique))
            ->addAttributeToSelect('name');

        $this->_row('<categories>');
//        $category = Mage::getModel('catalog/category')->load($this->_rootCategoryId);
//        $this->_row('<category id="' . $category->getId() . '"' . '>' . htmlspecialchars($category->getName()) . '</category>');
        foreach ($categoryCollection as $category) {
            $parent = '';
            if ($category->getParentId()) {
                $parent = 'parentId="' . $category->getParentId() . '"';
            }
            if (in_array($category->getId(), $this->_productCategoriesUnique)) {
                $this->_row('<category id="' . $category->getId() . '" ' . $parent . '>' . $category->getName() . '</category>');
            }
        }
        $this->_row('</categories>');
    }

    private function _writeProductsData()
    {
//        $fp = fopen(Mage::getBaseDir('var') . DS . 'log' . DS . 'mtdata.csv', 'w');
        $productCollection = $this->_getProductCollection();

        $productCollectionSize = $productCollection->getSize();

        $this->_row('<offers>');

        $ii = 1;
        for ($i = 0; $i < $productCollectionSize;) {
//            $a = microtime(true);

            $productCollection = $this
                ->_getProductCollection()
                ->setPageSize($this->_step)
                ->setCurPage($ii);

            foreach ($productCollection as $product) {
                $this->_productCategories[$product->getId()] = array();
            }

            $select = Mage::getSingleton('core/resource')->getConnection('core_read')
                ->select()
                ->from(Mage::getSingleton('core/resource')->getTableName('catalog/category_product'), 'category_id')
                ->columns(array('product_id'))
                ->where('product_id IN (?)', array_keys($this->_productCategories));
            foreach (Mage::getSingleton('core/resource')->getConnection('core_read')->fetchAll($select) as $pair) {
                $this->_productCategories[$pair['product_id']][] = (int)$pair['category_id'];
            }

            foreach ($productCollection as $product) {
                if (
                    count($this->_productCategories[$product->getId()]) == 0
                    || (
                        count($this->_productCategories[$product->getId()]) == 1
                        && $this->_productCategories[$product->getId()][0] == $this->_rootCategoryId
                    )
                ) {
                    continue;
                }
                $stockStatus = $product->getInventoryInStock();
                $availability = empty($stockStatus) ? 'false' : 'true';
                $this->_row('<offer id="' . $product->getId() . '" available="' . $availability . '">');
                $this->_row('<url>' . $this->_baseUrl . $product->getUrlKey() . '</url>');
                $price = $product->getFinalPrice();
                $price = number_format((float)$price, 2, '.', '');
                $this->_row('<price>' . $price . '</price>');
                if($product->getPrice() > $product->getFinalPrice()) {
                    $oldPrice = $product->getPrice();
                    $oldPrice = number_format((float)$oldPrice, 2, '.', '');
                    $this->_row('<oldprice>' . $oldPrice . '</oldprice>');

                    $discount = (((float)$product->getPrice() - (float)$product->getFinalPrice()) / (float)$product->getPrice())*100;
                    $discount = round((float)$discount);
                    $this->_row('<discount>' . $discount . '</discount>');
                }
                $this->_row('<currencyId>RUR</currencyId>');
                $found = false;
                foreach ($this->_productCategories[$product->getId()] as $categoryId) {
                    if (in_array($categoryId, $this->_finalCategories)) {
                        if (!in_array($categoryId, $this->_productCategoriesUnique)) {
                            $this->_productCategoriesUnique[] = $categoryId;
                        }
                        $this->_row('<categoryId>' . $categoryId . '</categoryId>');
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $this->_notFound[] = $product->getId();
                }
                $img = (string)Mage::helper('catalog/image')->init($product, 'image');
                $this->_row('<picture>' . $img . '</picture>');
                $this->_row('<name>' . htmlspecialchars($product->getName()) . '</name>');
                $this->_row('<typePrefix>' . htmlspecialchars($product->getAttributeText('product_category_name')) . '</typePrefix>');
                $this->_row('<vendor>' . htmlspecialchars($product->getAttributeText('manufacturer')) . '</vendor>');
                $this->_row('<vendorCode>' . htmlspecialchars($product->getData('name')) . '</vendorCode>');
                $this->_row('<model>' . htmlspecialchars($product->getData('name')) . '</model>');
                $this->_row('<description>' . htmlspecialchars($product->getName()) . '</description>');
                $this->_row('</offer>');
                $i++;
            }
            $this->_productCollection = null;
            $this->_productCategories = array();
            $ii++;
//            $a = microtime(true) - $a;
//            fputcsv($fp, array(memory_get_usage(), $product->getId(), $i, $a * 100000), "\t");
        }

        $this->_row('</offers>');
//        fclose($fp);
    }

    private function _getProductCollection()
    {
        if (empty($this->_productCollection)) {
            $this->_productCollection = Mage::getModel('catalog/product')
                ->getCollection()
                ->setStoreId($this->_storeId)
                ->addAttributeToSelect('*')
                ->addFieldToFilter('visibility', Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH)
                ->addAttributeToFilter('status', array('eq' => Mage_Catalog_Model_Product_Status::STATUS_ENABLED))
                ->addAttributeToFilter('attribute_set_id', array('nin' => $this->_limitAttributeSets));

            Mage::getSingleton('cataloginventory/stock')->addInStockFilterToCollection($this->_productCollection);

            $this->_productCollection->addFinalPrice()
                ->getSelect()
                ->where('price_index.final_price < price_index.price');
        }
        return $this->_productCollection;
    }

    private function _getTempProductsPath()
    {
        if (empty($this->_productsTmpFile)) {
            $this->_productsTmpFile = tempnam(sys_get_temp_dir(), 'vs7_salefeed_products_');
        }
        return $this->_productsTmpFile;
    }

    private function _getTempCategoriesPath()
    {
        if (empty($this->_categoriesTmpFile)) {
            $this->_categoriesTmpFile = tempnam(sys_get_temp_dir(), 'vs7_salefeed_categories_');
        }
        return $this->_categoriesTmpFile;
    }

    private function _getAllCategories()
    {
        if (!empty($this->_allCategories)) {
            return $this->_allCategories;
        }

        $this->_allCategories = array();

        $categoryCollection = Mage::getModel('catalog/category')
            ->getCollection()
            ->setStoreId($this->_storeId)
            ->addFieldToFilter('is_active', 1)
            ->addAttributeToFilter('path', array('like' => "1/{$this->_rootCategoryId}/%"))
            ->addAttributeToSelect('name');

        foreach ($categoryCollection as $category) {
            $this->_allCategories[$category->getId()] = $category->getName();
        }

        return $this->_allCategories;
    }

    private function _getFinalCategories()
    {
        $finalCategories = array();

        $subQuery = 'SELECT NULL FROM `' . Mage::getSingleton('core/resource')->getTableName('catalog/category') . '` WHERE parent_id = cce.entity_id';

        $select = Mage::getSingleton('core/resource')->getConnection('core_read')
            ->select()
            ->from(array('cce' => Mage::getSingleton('core/resource')->getTableName('catalog/category')), 'entity_id')
            ->where('NOT EXISTS (' . $subQuery .')');
        foreach (Mage::getSingleton('core/resource')->getConnection('core_read')->fetchAll($select) as $row) {
            $finalCategories[] = (int)$row['entity_id'];
        }

        return $finalCategories;
    }
}