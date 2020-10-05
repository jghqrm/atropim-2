<?php

namespace Pim\SelectManagers;

use Espo\Core\Exceptions\BadRequest;
use Pim\Core\SelectManagers\AbstractSelectManager;
use Pim\Services\GeneralStatisticsDashlet;
use Treo\Core\Utils\Util;

/**
 * Product select manager
 *
 * @author r.ratsun <r.ratsun@gmail.com>
 */
class Product extends AbstractSelectManager
{
    /**
     * @var string
     */
    protected $customWhere = '';

    /**
     * @inheritdoc
     */
    public function getSelectParams(array $params, $withAcl = false, $checkWherePermission = false)
    {
        // filtering by product types
        $params['where'][] = [
            'type'      => 'in',
            'attribute' => 'type',
            'value'     => array_keys($this->getMetadata()->get('pim.productType', []))
        ];

        // filtering by categories
        $this->filteringByCategories($params);

        // get product attributes filter
        $productAttributes = $this->getProductAttributeFilter($params);

        // get select params
        $selectParams = parent::getSelectParams($params, $withAcl, $checkWherePermission);

        // prepare custom where
        if (!isset($selectParams['customWhere'])) {
            $selectParams['customWhere'] = $this->customWhere;
        }

        // add product attributes filter
        $this->addProductAttributesFilter($selectParams, $productAttributes);

        return $selectParams;
    }

    /**
     * @inheritDoc
     */
    protected function textFilter($textFilter, &$result)
    {
        // call parent
        parent::textFilter($textFilter, $result);

        if (empty($result['whereClause'])) {
            return;
        }

        $scopes = $this->getMetadata()->get(['entityDefs', 'Product', 'collection'], []);

        if (isset($scopes['attributeTextFilterDisable']) && $scopes['attributeTextFilterDisable'] == true) {
            return;
        }

        // get last
        $last = array_pop($result['whereClause']);

        if (!isset($last['OR'])) {
            return;
        }

        // prepare text filter
        $textFilter = \addslashes($textFilter);

        // prepare rows
        $rows = [];

        // push for fields
        foreach ($last['OR'] as $name => $value) {
            $rows[] = "product." . Util::toUnderScore(str_replace('*', '', $name)) . " LIKE '" . \addslashes($value) . "'";
        }

        // get attributes ids
        $attributesIds = $this
            ->getEntityManager()
            ->nativeQuery("SELECT id FROM attribute WHERE type IN ('varchar','text','wysiwyg') AND deleted=0")
            ->fetchAll(\PDO::FETCH_ASSOC);
        $attributesIds = array_column($attributesIds, 'id');

        // prepare attributes values
        $attributesValues = ["value LIKE '%$textFilter%'"];
        if ($this->getConfig()->get('isMultilangActive', false) && !empty($locales = $this->getConfig()->get('inputLanguageList', []))) {
            foreach ($locales as $locale) {
                $attributesValues[] = "value_" . strtolower($locale) . " LIKE '%$textFilter%'";
            }
        }
        $attributesValues = implode(" OR ", $attributesValues);

        // get products ids
        $productsIds = $this
            ->getEntityManager()
            ->nativeQuery("SELECT product_id FROM product_attribute_value WHERE deleted=0 AND attribute_id IN ('" . implode("','", $attributesIds) . "') AND ($attributesValues)")
            ->fetchAll(\PDO::FETCH_ASSOC);
        $productsIds = array_column($productsIds, 'product_id');

        // push for attributes
        $rows[] = "product.id IN ('" . implode("','", $productsIds) . "')";

        // prepare custom where
        $result['customWhere'] .= " AND (" . implode(" OR ", $rows) . ")";
    }

    /**
     * Products without associated products filter
     *
     * @param $result
     */
    protected function boolFilterWithoutAssociatedProducts(&$result)
    {
        $result['whereClause'][] = [
            'id' => array_column($this->getProductWithoutAssociatedProduct(), 'id')
        ];
    }

    /**
     * @param array $result
     */
    protected function boolFilterOnlyCatalogProducts(&$result)
    {
        if (!empty($category = $this->getEntityManager()->getEntity('Category', (string)$this->getSelectCondition('notLinkedWithCategory')))) {
            // prepare ids
            $ids = ['-1'];

            // get root id
            if (empty($category->get('categoryParent'))) {
                $rootId = $category->get('id');
            } else {
                $tree = explode("|", (string)$category->get('categoryRoute'));
                $rootId = (!empty($tree[1])) ? $tree[1] : null;
            }

            if (!empty($rootId)) {
                $catalogs = $this
                    ->getEntityManager()
                    ->getRepository('Catalog')
                    ->distinct()
                    ->join('categories')
                    ->where(['categories.id' => $rootId])
                    ->find();

                if (count($catalogs) > 0) {
                    foreach ($catalogs as $catalog) {
                        $ids = array_merge($ids, array_column($catalog->get('products')->toArray(), 'id'));
                    }
                }
            }

            // prepare where
            $result['whereClause'][] = [
                'id' => $ids
            ];
        }
    }

    /**
     * Get product without AssociatedProduct
     *
     * @return array
     */
    protected function getProductWithoutAssociatedProduct(): array
    {
        return $this->fetchAll($this->getGeneralStatisticService()->getQueryProductWithoutAssociatedProduct());
    }

    /**
     * Products without Category filter
     *
     * @param $result
     */
    protected function boolFilterWithoutAnyCategory(&$result)
    {
        $result['whereClause'][] = [
            'id' => array_column($this->getProductWithoutCategory(), 'id')
        ];
    }

    /**
     * Get product without Category
     *
     * @return array
     */
    protected function getProductWithoutCategory(): array
    {
        return $this->fetchAll($this->getGeneralStatisticService()->getQueryProductWithoutCategory());
    }

    /**
     * Products without Image filter
     *
     * @param $result
     */
    protected function boolFilterWithoutImageAssets(&$result)
    {
        $result['whereClause'][] = [
            'id' => array_column($this->getProductWithoutImageAssets(), 'id')
        ];
    }

    /**
     * Get products without Image
     *
     * @return array
     */
    protected function getProductWithoutImageAssets(): array
    {
        return $this->fetchAll($this->getGeneralStatisticService()->getQueryProductWithoutImage());
    }

    /**
     * NotAssociatedProduct filter
     *
     * @param array $result
     */
    protected function boolFilterNotAssociatedProducts(&$result)
    {
        // prepare data
        $data = (array)$this->getSelectCondition('notAssociatedProducts');

        if (!empty($data['associationId'])) {
            $associatedProducts = $this->getAssociatedProducts($data['associationId'], $data['mainProductId']);
            foreach ($associatedProducts as $row) {
                $result['whereClause'][] = [
                    'id!=' => (string)$row['related_product_id']
                ];
            }
        }
    }

    /**
     * OnlySimple filter
     *
     * @param array $result
     */
    protected function boolFilterOnlySimple(&$result)
    {
        $result['whereClause'][] = [
            'type' => 'simpleProduct'
        ];
    }

    /**
     * Get assiciated products
     *
     * @param string $associationId
     * @param string $productId
     *
     * @return array
     */
    protected function getAssociatedProducts($associationId, $productId)
    {
        $pdo = $this->getEntityManager()->getPDO();

        $sql
            = 'SELECT
          related_product_id
        FROM
          associated_product
        WHERE
          main_product_id =' . $pdo->quote($productId) . '
          AND association_id = ' . $pdo->quote($associationId) . '
          AND deleted = 0';

        $sth = $pdo->prepare($sql);
        $sth->execute();

        return $sth->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * NotLinkedWithChannel filter
     *
     * @param array $result
     */
    protected function boolFilterNotLinkedWithChannel(&$result)
    {
        $channelId = (string)$this->getSelectCondition('notLinkedWithChannel');

        if (!empty($channelId)) {
            $channelProducts = $this->createService('Channel')->getProducts($channelId);
            foreach ($channelProducts as $row) {
                $result['whereClause'][] = [
                    'id!=' => (string)$row['productId']
                ];
            }
        }
    }

    /**
     * NotLinkedWithBrand filter
     *
     * @param array $result
     */
    protected function boolFilterNotLinkedWithBrand(array &$result)
    {
        // prepare data
        $brandId = (string)$this->getSelectCondition('notLinkedWithBrand');

        if (!empty($brandId)) {
            // get Products linked with brand
            $products = $this->getBrandProducts($brandId);
            foreach ($products as $row) {
                $result['whereClause'][] = [
                    'id!=' => $row['productId']
                ];
            }
        }
    }

    /**
     * Get productIds related with brand
     *
     * @param string $brandId
     *
     * @return array
     */
    protected function getBrandProducts(string $brandId): array
    {
        $pdo = $this->getEntityManager()->getPDO();

        $sql
            = 'SELECT id AS productId
                FROM product
                WHERE deleted = 0 
                      AND brand_id = :brandId';

        $sth = $pdo->prepare($sql);
        $sth->execute(['brandId' => $brandId]);

        return $sth->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * NotLinkedWithProductFamily filter
     *
     * @param array $result
     */
    protected function boolFilterNotLinkedWithProductFamily(array &$result)
    {
        // prepare data
        $productFamilyId = (string)$this->getSelectCondition('notLinkedWithProductFamily');

        if (!empty($productFamilyId)) {
            // get Products linked with brand
            $products = $this->getProductFamilyProducts($productFamilyId);
            foreach ($products as $row) {
                $result['whereClause'][] = [
                    'id!=' => $row['productId']
                ];
            }
        }
    }

    /**
     * Get productIds related with productFamily
     *
     * @param string $productFamilyId
     *
     * @return array
     */
    protected function getProductFamilyProducts(string $productFamilyId): array
    {
        $pdo = $this->getEntityManager()->getPDO();

        $sql
            = 'SELECT id AS productId
                FROM product
                WHERE deleted = 0
                      AND product_family_id = :productFamilyId';

        $sth = $pdo->prepare($sql);
        $sth->execute(['productFamilyId' => $productFamilyId]);

        return $sth->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * NotLinkedWithPackaging filter
     *
     * @param array $result
     */
    protected function boolFilterNotLinkedWithPackaging(&$result)
    {
        // find products
        $products = $this
            ->getEntityManager()
            ->getRepository('Product')
            ->where(
                [
                    'packagingId' => (string)$this->getSelectCondition('notLinkedWithPackaging')
                ]
            )
            ->find();

        if (!empty($products)) {
            foreach ($products as $product) {
                $result['whereClause'][] = [
                    'id!=' => $product->get('id')
                ];
            }
        }
    }

    /**
     * Fetch all result from DB
     *
     * @param string $query
     *
     * @return array
     */
    protected function fetchAll(string $query): array
    {
        $sth = $this->getEntityManager()->getPDO()->prepare($query);
        $sth->execute();

        return $sth->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Create dashlet service
     *
     * @return GeneralStatisticsDashlet
     */
    protected function getGeneralStatisticService(): GeneralStatisticsDashlet
    {
        return $this->createService('GeneralStatisticsDashlet');
    }

    /**
     * NotLinkedWithProductSerie filter
     *
     * @param $result
     */
    protected function boolFilterNotLinkedWithProductSerie(&$result)
    {
        //find products
        $products = $this
            ->getEntityManager()
            ->getRepository('Product')
            ->join(['productSerie'])
            ->where(
                [
                    'productSerie.id' => (string)$this->getSelectCondition('notLinkedWithProductSerie')
                ]
            )
            ->find();

        // add product ids to whereClause
        if (!empty($products)) {
            foreach ($products as $product) {
                $result['whereClause'][] = [
                    'id!=' => $product->get('id')
                ];
            }
        }
    }

    /**
     * @param array $result
     *
     * @throws BadRequest
     * @throws \Espo\Core\Exceptions\Error
     */
    protected function boolFilterLinkedWithCategory(array &$result)
    {
        /** @var \Pim\Entities\Category $category */
        $category = $this->getEntityManager()->getEntity('Category', $this->getSelectCondition('linkedWithCategory'));
        if (empty($category)) {
            throw new BadRequest('No such category');
        }

        // collect categories
        $categoriesIds = array_column($category->getChildren()->toArray(), 'id');
        $categoriesIds[] = $category->get('id');

        // prepare categories ids
        $ids = implode("','", $categoriesIds);

        // prepare custom where
        if (!isset($result['customWhere'])) {
            $result['customWhere'] = '';
        }

        // set custom where
        $result['customWhere'] .= " AND product.id IN (SELECT product_id FROM product_category WHERE product_id IS NOT NULL AND deleted=0 AND category_id IN ('$ids'))";
    }

    /**
     * @param array $params
     *
     * @return array
     */
    protected function getProductAttributeFilter(array &$params): array
    {
        // prepare result
        $result = [];

        if (!empty($params['where']) && is_array($params['where'])) {
            $where = [];
            foreach ($params['where'] as $row) {
                if (empty($row['isAttribute'])) {
                    $where[] = $row;
                } else {
                    $result[] = $row;
                }
            }
            $params['where'] = $where;
        }

        return $result;
    }

    /**
     * @param array $selectParams
     * @param array $attributes
     */
    protected function addProductAttributesFilter(array &$selectParams, array $attributes): void
    {
        foreach ($attributes as $row) {
            // find prepare method
            $method = 'prepareType' . ucfirst($row['type']);
            if (!method_exists($this, $method)) {
                $method = 'prepareTypeDefault';
            }

            // prepare where
            $where = $this->{$method}($row);

            // create select params
            $sp = $this
                ->createSelectManager('ProductAttributeValue')
                ->getSelectParams(['where' => [$where]], true, true);
            $sp['select'] = ['productId'];

            // create sql
            $sql = $this
                ->getEntityManager()
                ->getQuery()
                ->createSelectQuery('ProductAttributeValue', $sp);

            // for case sensitive
            $sql = str_replace('product_attribute_value.value IN', 'CAST(product_attribute_value.value AS BINARY) IN', $sql);

            // for umlauts
            $sql = str_replace('Ä', '\\\\\\\\u00c4', $sql);
            $sql = str_replace('ä', '\\\\\\\\u00e4', $sql);
            $sql = str_replace('Ë', '\\\\\\\\u00cb', $sql);
            $sql = str_replace('ë', '\\\\\\\\u00eb', $sql);
            $sql = str_replace('Ï', '\\\\\\\\u00cf', $sql);
            $sql = str_replace('ï', '\\\\\\\\u00ef', $sql);
            $sql = str_replace('N̈', 'N\\\\\\\\u0308', $sql);
            $sql = str_replace('n̈', 'n\\\\\\\\u0308', $sql);
            $sql = str_replace('Ö', '\\\\\\\\u00d6', $sql);
            $sql = str_replace('ö', '\\\\\\\\u00f6', $sql);
            $sql = str_replace('T̈', 'T\\\\\\\\u0308', $sql);
            $sql = str_replace('ẗ', '\\\\\\\\u1e97', $sql);
            $sql = str_replace('Ü', '\\\\\\\\u00dc', $sql);
            $sql = str_replace('ü', '\\\\\\\\u00fc', $sql);
            $sql = str_replace('Ÿ', '\\\\\\\\u0178', $sql);
            $sql = str_replace('ÿ', '\\\\\\\\u00ff', $sql);

            // prepare custom where
            $selectParams['customWhere'] .= ' AND product.id IN (' . $sql . ')';
        }
    }

    /**
     * @param string $attributeId
     *
     * @return array
     */
    protected function getValues(string $attributeId): array
    {
        // prepare result
        $result = ['value'];

        if ($this->getConfig()->get('isMultilangActive', false) && !empty($locales = $this->getConfig()->get('inputLanguageList', []))) {
            // is attribute multi-languages ?
            $isMultiLang = $this
                ->getEntityManager()
                ->getRepository('Attribute')
                ->select(['isMultilang'])
                ->where(['id' => $attributeId])
                ->findOne()
                ->get('isMultilang');

            if ($isMultiLang) {
                foreach ($locales as $locale) {
                    $result[] = 'value' . ucfirst(Util::toCamelCase(strtolower($locale)));
                }
            }
        }

        return $result;
    }

    /**
     * @param array $row
     *
     * @return array
     */
    protected function prepareTypeIsTrue(array $row): array
    {
        $where = ['type' => 'or', 'value' => []];
        foreach ($this->getValues($row['attribute']) as $v) {
            $where['value'][] = [
                'type'  => 'and',
                'value' => [
                    [
                        'type'      => 'equals',
                        'attribute' => 'attributeId',
                        'value'     => $row['attribute']
                    ],
                    [
                        'type'      => 'equals',
                        'attribute' => $v,
                        'value'     => '1'
                    ]
                ]
            ];
        }

        return $where;
    }

    /**
     * @param array $row
     *
     * @return array
     */
    protected function prepareTypeIsFalse(array $row): array
    {
        $where = [
            'type'  => 'and',
            'value' => [
                [
                    'type'      => 'equals',
                    'attribute' => 'attributeId',
                    'value'     => $row['attribute']
                ],
                [
                    'type'  => 'or',
                    'value' => []
                ],
            ]
        ];

        foreach ($this->getValues($row['attribute']) as $v) {
            $where['value'][1]['value'][] = [
                'type'      => 'isNull',
                'attribute' => $v
            ];
            $where['value'][1]['value'][] = [
                'type'      => 'notEquals',
                'attribute' => $v,
                'value'     => '1'
            ];
        }

        return $where;
    }

    /**
     * @param array $row
     *
     * @return array
     */
    protected function prepareTypeArrayAnyOf(array $row): array
    {
        $where = [
            'type'  => 'and',
            'value' => [
                [
                    'type'      => 'equals',
                    'attribute' => 'attributeId',
                    'value'     => $row['attribute']
                ],
                [
                    'type'  => 'or',
                    'value' => []
                ],
            ]
        ];

        // prepare values
        $values = (empty($row['value'])) ? [md5('no-such-value-' . time())] : $row['value'];

        foreach ($values as $value) {
            $where['value'][1]['value'][] = [
                'type'      => 'like',
                'attribute' => 'value',
                'value'     => "%\"$value\"%"
            ];
        }

        return $where;
    }

    /**
     * @param array $row
     *
     * @return array
     */
    protected function prepareTypeArrayNoneOf(array $row): array
    {
        $where = [
            'type'  => 'and',
            'value' => [
                [
                    'type'      => 'equals',
                    'attribute' => 'attributeId',
                    'value'     => $row['attribute']
                ],
                [
                    'type'  => 'or',
                    'value' => []
                ],
            ]
        ];

        // prepare values
        $values = (empty($row['value'])) ? [md5('no-such-value-' . time())] : $row['value'];

        foreach ($values as $value) {
            $where['value'][1]['value'][] = [
                'type'      => 'notLike',
                'attribute' => 'value',
                'value'     => "%\"$value\"%"
            ];
        }

        return $where;
    }

    /**
     * @param array $row
     *
     * @return array
     */
    protected function prepareTypeArrayIsEmpty(array $row): array
    {
        $where = [
            'type'  => 'and',
            'value' => [
                [
                    'type'      => 'equals',
                    'attribute' => 'attributeId',
                    'value'     => $row['attribute']
                ],
                [
                    'type'  => 'or',
                    'value' => [
                        [
                            'type'      => 'isNull',
                            'attribute' => 'value'
                        ],
                        [
                            'type'      => 'equals',
                            'attribute' => 'value',
                            'value'     => ''
                        ],
                        [
                            'type'      => 'equals',
                            'attribute' => 'value',
                            'value'     => '[]'
                        ]
                    ]
                ],
            ]
        ];

        return $where;
    }

    /**
     * @param array $row
     *
     * @return array
     */
    protected function prepareTypeArrayIsNotEmpty(array $row): array
    {
        $where = [
            'type'  => 'and',
            'value' => [
                [
                    'type'      => 'equals',
                    'attribute' => 'attributeId',
                    'value'     => $row['attribute']
                ],
                [
                    'type'      => 'isNotNull',
                    'attribute' => 'value'
                ],
                [
                    'type'      => 'notEquals',
                    'attribute' => 'value',
                    'value'     => ''
                ],
                [
                    'type'      => 'notEquals',
                    'attribute' => 'value',
                    'value'     => '[]'
                ]
            ]
        ];

        return $where;
    }


    /**
     * @param array $row
     *
     * @return array
     */
    protected function prepareTypeDefault(array $row): array
    {
        $where = ['type' => 'or', 'value' => []];
        foreach ($this->getValues($row['attribute']) as $v) {
            $where['value'][] = [
                'type'  => 'and',
                'value' => [
                    [
                        'type'      => 'equals',
                        'attribute' => 'attributeId',
                        'value'     => $row['attribute']
                    ],
                    [
                        'type'      => $row['type'],
                        'attribute' => $v,
                        'value'     => $row['value']
                    ]
                ]
            ];
        }

        return $where;
    }

    /**
     * @param array $params
     */
    protected function filteringByCategories(array &$params): void
    {
        foreach ($params['where'] as $k => $row) {
            if ($row['attribute'] == 'categories') {
                if (!empty($row['value'])) {
                    $categories = [];
                    foreach ($row['value'] as $id) {
                        $dbData = $this->fetchAll("SELECT id FROM category WHERE (id='$id' OR category_route LIKE '%|$id|%') AND deleted=0");
                        $categories = array_merge($categories, array_column($dbData, 'id'));
                    }
                    $innerSql = "SELECT product_id FROM product_category WHERE deleted=0 AND category_id IN ('" . implode("','", $categories) . "')";
                }

                switch ($row['type']) {
                    case 'linkedWith':
                        if (!empty($innerSql)) {
                            $this->customWhere .= " AND product.id IN ($innerSql) ";
                        }
                        break;
                    case 'isNotLinked':
                        $this->customWhere .= " AND product.id NOT IN (SELECT product_id FROM product_category WHERE deleted=0) ";
                        break;
                    case 'isLinked':
                        $this->customWhere .= " AND product.id IN (SELECT product_id FROM product_category WHERE deleted=0) ";
                        break;
                    case 'notLinkedWith':
                        if (!empty($innerSql)) {
                            $this->customWhere .= " AND product.id NOT IN ($innerSql) ";
                        }
                        break;
                }
                unset($params['where'][$k]);
            }
        }

        $params['where'] = array_values($params['where']);
    }
}