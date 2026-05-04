<?php
/**
 * Schema.org JSON-LD Generator.
 * Generates structured data markup for different page types.
 */

require_once __DIR__ . '/helpers.php';

/**
 * Generate Organization schema.
 */
function schema_organization(array $site): string
{
    $data = [
        '@context'    => 'https://schema.org',
        '@type'       => 'Organization',
        'name'        => $site['name'],
        'url'         => 'https://' . $site['domain'],
        'description' => $site['brand_tone'] ?? '',
    ];

    $social = [];
    // Could be expanded with actual social links from scanner
    if (!empty($social)) {
        $data['sameAs'] = $social;
    }

    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

/**
 * Generate LocalBusiness schema.
 */
function schema_local_business(array $site, array $business_info = []): string
{
    $data = [
        '@context'    => 'https://schema.org',
        '@type'       => 'ProfessionalService',
        'name'        => $site['name'],
        'url'         => 'https://' . $site['domain'],
        'description' => $business_info['description'] ?? $site['brand_tone'] ?? '',
    ];

    if (!empty($business_info['address'])) {
        $data['address'] = [
            '@type'           => 'PostalAddress',
            'streetAddress'   => $business_info['address']['street'] ?? '',
            'addressLocality' => $business_info['address']['city'] ?? '',
            'addressRegion'   => $business_info['address']['state'] ?? '',
            'postalCode'      => $business_info['address']['zip'] ?? '',
            'addressCountry'  => $business_info['address']['country'] ?? 'IN',
        ];
    }

    if (!empty($business_info['phone'])) $data['telephone'] = $business_info['phone'];
    if (!empty($business_info['email'])) $data['email'] = $business_info['email'];
    if (!empty($business_info['hours'])) {
        $data['openingHoursSpecification'] = [
            '@type'     => 'OpeningHoursSpecification',
            'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
            'opens'     => $business_info['hours']['open'] ?? '09:00',
            'closes'    => $business_info['hours']['close'] ?? '18:00',
        ];
    }

    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

/**
 * Generate BlogPosting schema for a blog post.
 */
function schema_blog_post(array $post, array $site): string
{
    $url = 'https://' . $site['domain'] . ($site['blog_path'] ?: '/blog') . '/' . $post['slug'];
    $word_count = str_word_count(strip_tags($post['body']));

    $data = [
        '@context'      => 'https://schema.org',
        '@type'         => $post['type'] === 'news' ? 'NewsArticle' : 'BlogPosting',
        'headline'      => $post['title'],
        'description'   => $post['seo_description'] ?? truncate(strip_tags($post['body']), 160),
        'url'           => $url,
        'datePublished' => $post['published_at'] ?? $post['created_at'],
        'dateModified'  => $post['updated_at'] ?? $post['created_at'],
        'wordCount'     => $word_count,
        'author'        => [
            '@type' => 'Organization',
            'name'  => $site['name'],
            'url'   => 'https://' . $site['domain'],
        ],
        'publisher'     => [
            '@type' => 'Organization',
            'name'  => $site['name'],
        ],
        'mainEntityOfPage' => [
            '@type' => 'WebPage',
            '@id'   => $url,
        ],
    ];

    $tags = json_decode($post['tags'] ?? '[]', true) ?: [];
    if (!empty($tags)) {
        $data['keywords'] = implode(', ', $tags);
    }

    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

/**
 * Generate FAQPage schema from Q&A pairs.
 */
function schema_faq(array $faqs): string
{
    $entities = [];
    foreach ($faqs as $faq) {
        $entities[] = [
            '@type'          => 'Question',
            'name'           => $faq['question'],
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text'  => $faq['answer'],
            ],
        ];
    }

    $data = [
        '@context'   => 'https://schema.org',
        '@type'      => 'FAQPage',
        'mainEntity' => $entities,
    ];

    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

/**
 * Generate Product schema.
 */
function schema_product(array $product): string
{
    $data = [
        '@context'    => 'https://schema.org',
        '@type'       => 'Product',
        'name'        => $product['name'],
        'description' => $product['description'] ?? '',
        'url'         => $product['url'] ?? '',
    ];

    if (!empty($product['price'])) {
        $data['offers'] = [
            '@type'         => 'Offer',
            'price'         => $product['price'],
            'priceCurrency' => $product['currency'] ?? 'USD',
            'availability'  => 'https://schema.org/InStock',
        ];
    }

    if (!empty($product['image'])) {
        $data['image'] = $product['image'];
    }

    if (!empty($product['brand'])) {
        $data['brand'] = ['@type' => 'Brand', 'name' => $product['brand']];
    }

    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

/**
 * Generate BreadcrumbList schema.
 */
function schema_breadcrumbs(array $items, string $base_url): string
{
    $list = [];
    foreach ($items as $i => $item) {
        $list[] = [
            '@type'    => 'ListItem',
            'position' => $i + 1,
            'name'     => $item['name'],
            'item'     => $item['url'] ?? $base_url,
        ];
    }

    $data = [
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => $list,
    ];

    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

/**
 * Generate WebSite schema with SearchAction (sitelinks search box).
 */
function schema_website(array $site): string
{
    $url = 'https://' . $site['domain'];

    $data = [
        '@context'        => 'https://schema.org',
        '@type'           => 'WebSite',
        'name'            => $site['name'],
        'url'             => $url,
        'potentialAction' => [
            '@type'       => 'SearchAction',
            'target'      => $url . '/search?q={search_term_string}',
            'query-input' => 'required name=search_term_string',
        ],
    ];

    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

/**
 * Generate Service schema.
 */
function schema_service(string $name, string $description, string $url, string $provider_name): string
{
    $data = [
        '@context'    => 'https://schema.org',
        '@type'       => 'Service',
        'name'        => $name,
        'description' => $description,
        'url'         => $url,
        'provider'    => [
            '@type' => 'Organization',
            'name'  => $provider_name,
        ],
    ];

    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
