<?php
/**
 * ContentAgent Configuration
 * Copy this file to config.php and fill in your values.
 * NEVER commit config.php to git.
 */

return [
    // App
    'app_name'    => 'ContentAgent',
    'app_url'     => 'https://contentagent.yourdomain.com',
    'app_env'     => 'development', // development | production
    'app_debug'   => true,
    'timezone'    => 'Asia/Kolkata',

    // Database
    'db_host'     => '127.0.0.1',
    'db_port'     => 3306,
    'db_name'     => 'contentagent',
    'db_user'     => 'root',
    'db_pass'     => '',
    'db_charset'  => 'utf8mb4',

    // Claude Haiku API
    'haiku_api_key'    => 'sk-ant-xxxxx',
    'haiku_model'      => 'claude-haiku-4-5-20251001',
    'haiku_max_tokens' => 4096,

    // Session
    'session_name'     => 'contentagent_sess',
    'session_lifetime' => 86400, // 24 hours

    // Security
    'csrf_token_name'  => '_csrf_token',
    'api_key'          => '', // for external API access

    // Paths
    'log_path'    => __DIR__ . '/../logs',
    'cache_path'  => __DIR__ . '/../cache',

    // Blog engine
    'blog_posts_per_page' => 10,

    // Agent settings
    'agent_posts_per_week'   => 4,
    'agent_news_per_day'     => 5,
    'agent_min_word_count'   => 800,
    'agent_max_word_count'   => 1200,

    // Google Search Console (OAuth2)
    'google_client_id'     => '',
    'google_client_secret' => '',

    // LinkedIn (OAuth2)
    'linkedin_client_id'     => '',
    'linkedin_client_secret' => '',

    // Twitter/X (OAuth2 with PKCE)
    'twitter_client_id'     => '',
    'twitter_client_secret' => '',

    // Facebook + Instagram (OAuth2)
    'facebook_app_id'     => '',
    'facebook_app_secret' => '',

    // Shopify (OAuth2 — public/custom app via Partner or Dev Dashboard)
    // Redirect URI to register: {app_url}/api/oauth/shopify-callback.php
    // Scopes: write_content, write_url_redirects, read_products, read_themes
    'shopify_client_id'     => '',
    'shopify_client_secret' => '',

    // Stripe Billing
    'stripe_secret_key'       => '',
    'stripe_webhook_secret'   => '',
    'stripe_price_starter'    => '',
    'stripe_price_growth'     => '',
    'stripe_price_agency'     => '',

    // Auto-deploy (GitHub webhook)
    'deploy_secret' => '', // GitHub webhook secret
    'deploy_path'   => '/opt/contentagent',

    // Rate limiting
    'rate_limit_requests'    => 60,
    'rate_limit_window'      => 60, // seconds
];
