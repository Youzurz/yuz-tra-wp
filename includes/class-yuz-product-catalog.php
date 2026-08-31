<?php
defined('ABSPATH') || exit;

/** Central, contextual product catalog. Services remain optional and external. */
final class YUZ_Product_Catalog {
    public static function all(): array {
        $products = [
            'yuz-tra' => [
                'slug' => 'yuz-tra', 'name' => 'YUZ-TRA',
                'summary' => 'Éditeur visuel, catalogue de chaînes et traduction assistée.',
                'url' => 'https://youzurz.com/fr/yuz-tra', 'installed' => true,
            ],
            'yuz-woo' => [
                'slug' => 'yuz-woo', 'name' => 'YUZ-WOO',
                'summary' => 'Parcours WooCommerce et services associés.',
                'url' => 'https://youzurz.com/fr/yuz-woo',
                'installed' => function_exists('wc_get_order'),
            ],
            'yuz-qon' => [
                'slug' => 'yuz-qon', 'name' => 'YUZ-QON',
                'summary' => 'Rapprochement et suivi des paiements.',
                'url' => 'https://youzurz.com/fr/yuz-qon',
                'installed' => class_exists('Yuz_Qon_Plugin'),
            ],
            'yuz-doo' => [
                'slug' => 'yuz-doo', 'name' => 'YUZ-DOO',
                'summary' => 'Chaîne comptable et synchronisation des écritures.',
                'url' => 'https://youzurz.com/fr/yuz-doo',
                'installed' => class_exists('Yuz_Doo_Plugin'),
            ],
        ];
        foreach ($products as &$product) $product['installed'] = (bool) ($product['installed'] ?? false);
        unset($product);
        return (array) apply_filters('yuz_product_catalog', $products);
    }

    public static function current_offers(): array {
        return (array) apply_filters('yuz_tra_product_offers', [
            ['slug' => 'personal', 'name' => 'Personal', 'price' => '9 € / mois', 'summary' => 'Un site et traduction assistée.'],
            ['slug' => 'pro', 'name' => 'Pro', 'price' => '29 € / mois', 'summary' => 'Traitements de masse, glossaire et mémoire.'],
            ['slug' => 'agency', 'name' => 'Agency', 'price' => '79 € / mois', 'summary' => 'Équipes, multi-sites et accompagnement.'],
        ], 'yuz-tra');
    }
}
