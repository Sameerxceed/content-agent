<?php
/**
 * Shopify Admin GraphQL client.
 *
 * Built because Shopify's "app automation tokens" (prefixed `atkn_`, issued by
 * the new Dev Dashboard since Jan 1 2026) don't accept the REST Admin API —
 * only the GraphQL Admin API. The old `shpat_` Custom App tokens still work
 * on REST, so we keep both paths and route by token prefix.
 *
 * Token routing convention (used across includes/integrations/shopify.php
 * and includes/connectors/shopify.php):
 *   - shpat_*  → legacy REST path via shopify_admin_call()
 *   - atkn_*   → GraphQL path via shopify_graphql_call()  (this file)
 *   - shpca_*  → also REST (legacy custom-app from CLI)
 *
 * Use shopify_uses_graphql($token) at call sites to pick the path.
 */

require_once __DIR__ . '/../helpers.php';

const SHOPIFY_GQL_API_VERSION = '2024-10';
const SHOPIFY_GQL_TIMEOUT     = 25;
const SHOPIFY_GQL_DELAY_US    = 600000; // match REST: 0.6s = ~1.6 req/sec

function shopify_uses_graphql(string $token): bool
{
    return str_starts_with($token, 'atkn_');
}

function shopify_gql_endpoint(string $shop_url): string
{
    return rtrim($shop_url, '/') . '/admin/api/' . SHOPIFY_GQL_API_VERSION . '/graphql.json';
}

/**
 * Low-level GraphQL call. Returns ['status' => int, 'data' => array|null,
 * 'errors' => array|null, 'error' => string|null].
 *
 * `errors` (plural) is GraphQL's top-level errors array (syntax / auth).
 * `error` (singular) is a single short string we synthesize for callers that
 * just want a yes/no.
 */
function shopify_graphql_call(string $shop_url, string $token, string $query, array $variables = [], int $retries = 1): array
{
    $payload = ['query' => $query];
    if ($variables) $payload['variables'] = $variables;

    $ch = curl_init(shopify_gql_endpoint($shop_url));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => SHOPIFY_GQL_TIMEOUT,
        CURLOPT_HTTPHEADER     => [
            'X-Shopify-Access-Token: ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err && $code === 0) {
        return ['status' => 0, 'data' => null, 'errors' => null, 'error' => $err];
    }
    if ($code === 429 && $retries > 0) {
        usleep(2000000);
        return shopify_graphql_call($shop_url, $token, $query, $variables, $retries - 1);
    }

    $decoded = json_decode((string)$body, true);
    $data    = is_array($decoded) ? ($decoded['data'] ?? null) : null;
    $gqlErrs = is_array($decoded) ? ($decoded['errors'] ?? null) : null;

    $err_str = null;
    if ($code >= 400) {
        $err_str = $gqlErrs
            ? (is_string($gqlErrs[0]['message'] ?? null) ? $gqlErrs[0]['message'] : json_encode($gqlErrs))
            : 'HTTP ' . $code;
    } elseif ($gqlErrs) {
        // 200-with-errors — usually means auth scope or query shape issue.
        $err_str = is_string($gqlErrs[0]['message'] ?? null) ? $gqlErrs[0]['message'] : json_encode($gqlErrs);
    }

    return [
        'status' => $code,
        'data'   => $data,
        'errors' => $gqlErrs,
        'error'  => $err_str,
    ];
}

/**
 * Verify the token by querying the shop. Same return shape as
 * shopify_admin_verify() so callers can swap freely.
 */
function shopify_graphql_verify(string $shop_url, string $token): array
{
    $q = 'query { shop { name primaryDomain { url host } } }';
    $r = shopify_graphql_call($shop_url, $token, $q);
    if ($r['error']) return ['ok' => false, 'shop' => null, 'error' => $r['error']];
    $shop = $r['data']['shop'] ?? null;
    if (!$shop) return ['ok' => false, 'shop' => null, 'error' => 'shop_not_found'];
    // Reshape to match REST verify return.
    return [
        'ok'    => true,
        'shop'  => [
            'name'   => $shop['name'] ?? '',
            'domain' => $shop['primaryDomain']['host'] ?? '',
        ],
        'error' => null,
    ];
}

/**
 * Create a URL redirect via GraphQL `urlRedirectCreate`. Returns the same
 * shape as shopify_admin_create_redirect(): ['success', 'id', 'error'].
 * On duplicate path, Shopify returns a userErrors entry — we map that to
 * error='duplicate_or_conflict' so the caller treats it the same as REST.
 */
function shopify_graphql_create_redirect(string $shop_url, string $token, string $path, string $target): array
{
    if ($path === '' || $target === '') {
        return ['success' => false, 'id' => null, 'error' => 'path and target required'];
    }

    $mutation = <<<'GQL'
    mutation urlRedirectCreate($urlRedirect: UrlRedirectInput!) {
      urlRedirectCreate(urlRedirect: $urlRedirect) {
        urlRedirect { id path target }
        userErrors { field message }
      }
    }
    GQL;

    $r = shopify_graphql_call($shop_url, $token, $mutation, [
        'urlRedirect' => ['path' => $path, 'target' => $target],
    ]);
    usleep(SHOPIFY_GQL_DELAY_US);

    if ($r['error'] && !$r['data']) {
        return ['success' => false, 'id' => null, 'error' => $r['error']];
    }

    $payload     = $r['data']['urlRedirectCreate'] ?? [];
    $user_errors = $payload['userErrors'] ?? [];
    $created     = $payload['urlRedirect'] ?? null;

    if ($user_errors) {
        $msg = strtolower(json_encode($user_errors));
        // Shopify says "Path has already been taken" on dupes.
        if (strpos($msg, 'already been taken') !== false || strpos($msg, 'already exists') !== false) {
            return ['success' => false, 'id' => null, 'error' => 'duplicate_or_conflict'];
        }
        return ['success' => false, 'id' => null, 'error' => $user_errors[0]['message'] ?? 'userError'];
    }

    if (!$created) {
        return ['success' => false, 'id' => null, 'error' => 'no_redirect_returned'];
    }
    // GraphQL ID is a GID string like "gid://shopify/UrlRedirect/12345" — we
    // keep it whole because external_id is varchar in redirect_map.
    return ['success' => true, 'id' => $created['id'] ?? null, 'error' => null];
}

/**
 * Return the GID of the first blog on the store (Shopify's "News" default).
 * Same purpose as shopify_get_default_blog() but returns a GID string instead
 * of a numeric REST id, because GraphQL article mutations need a GID.
 */
function shopify_graphql_get_default_blog_gid(string $shop_url, string $token): ?string
{
    $q = 'query { blogs(first: 1) { edges { node { id handle title } } } }';
    $r = shopify_graphql_call($shop_url, $token, $q);
    if ($r['error']) return null;
    return $r['data']['blogs']['edges'][0]['node']['id'] ?? null;
}

/**
 * Create a Shopify blog article via GraphQL `articleCreate`.
 *
 * $blog_gid may be NULL — we look up the default blog if so. Returns the
 * same shape as shopify_push_post(): ['success', 'remote_id', 'slug', 'url',
 * 'error']. `remote_id` is the GID string (not a numeric REST id).
 */
function shopify_graphql_create_article(array $post, string $shop_url, string $token, ?string $blog_gid = null): array
{
    if (!$blog_gid) {
        $blog_gid = shopify_graphql_get_default_blog_gid($shop_url, $token);
        if (!$blog_gid) {
            return ['success' => false, 'error' => 'no_blog_found_on_store', 'remote_id' => null];
        }
    }

    $tags = json_decode($post['tags'] ?? '[]', true) ?: [];
    if (!is_array($tags)) $tags = [];

    $mutation = <<<'GQL'
    mutation articleCreate($article: ArticleCreateInput!) {
      articleCreate(article: $article) {
        article { id handle title }
        userErrors { field message code }
      }
    }
    GQL;

    $article_input = [
        'blogId'      => $blog_gid,
        'title'       => $post['title'] ?? '',
        'body'        => $post['body'] ?? '',
        'summary'     => $post['excerpt'] ?? '',
        'tags'        => $tags,
        'isPublished' => true,
        'author'      => ['name' => $post['author'] ?? 'ContentAgent'],
    ];
    // Shopify ArticleCreateInput expects ISO8601 for publishDate.
    if (!empty($post['published_at'])) {
        $article_input['publishDate'] = date('c', strtotime($post['published_at']));
    }
    // Slug ("handle") — Shopify auto-generates if omitted; we pass ours to keep
    // URLs predictable for redirect targets.
    if (!empty($post['slug'])) {
        $article_input['handle'] = $post['slug'];
    }

    $r = shopify_graphql_call($shop_url, $token, $mutation, ['article' => $article_input]);

    if ($r['error'] && !$r['data']) {
        return ['success' => false, 'error' => $r['error'], 'remote_id' => null];
    }

    $payload     = $r['data']['articleCreate'] ?? [];
    $user_errors = $payload['userErrors'] ?? [];
    $article     = $payload['article'] ?? null;

    if ($user_errors) {
        $first = $user_errors[0] ?? [];
        $msg   = $first['message'] ?? 'userError';
        if (($first['code'] ?? '') === 'TAKEN' || strpos(strtolower($msg), 'already been taken') !== false) {
            return ['success' => false, 'error' => 'duplicate_handle', 'remote_id' => null];
        }
        return ['success' => false, 'error' => $msg, 'remote_id' => null];
    }

    if (!$article) {
        return ['success' => false, 'error' => 'no_article_returned', 'remote_id' => null];
    }

    return [
        'success'   => true,
        'error'     => null,
        'remote_id' => $article['id'] ?? null,
        'slug'      => $article['handle'] ?? ($post['slug'] ?? ''),
        // Shopify GraphQL article doesn't return public URL directly — synthesize
        // the standard /blogs/<blog-handle>/<article-handle> shape. For the default
        // blog this is /blogs/news/<handle>.
        'url'       => rtrim($shop_url, '/') . '/blogs/news/' . ($article['handle'] ?? ''),
    ];
}

/**
 * List existing redirects via GraphQL paginated. Returns flat
 * [{id, path, target}] matching shopify_admin_list_redirects().
 */
function shopify_graphql_list_redirects(string $shop_url, string $token, int $cap = 1000): array
{
    $out    = [];
    $cursor = null;
    $page_size = 250;

    while (count($out) < $cap) {
        $after = $cursor ? ', after: "' . addslashes($cursor) . '"' : '';
        $q = "query { urlRedirects(first: {$page_size}{$after}) {
                edges { cursor node { id path target } }
                pageInfo { hasNextPage }
              } }";
        $r = shopify_graphql_call($shop_url, $token, $q);
        if ($r['error']) break;

        $edges = $r['data']['urlRedirects']['edges'] ?? [];
        if (!$edges) break;
        foreach ($edges as $edge) {
            $node = $edge['node'] ?? [];
            $out[] = [
                'id'     => $node['id'] ?? null,
                'path'   => $node['path'] ?? '',
                'target' => $node['target'] ?? '',
            ];
            $cursor = $edge['cursor'] ?? null;
            if (count($out) >= $cap) break 2;
        }
        if (empty($r['data']['urlRedirects']['pageInfo']['hasNextPage'])) break;
        usleep(SHOPIFY_GQL_DELAY_US);
    }
    return $out;
}

/**
 * Delete a redirect by GID. Used when "reverting" an applied row that was
 * pushed via GraphQL (REST and GraphQL IDs don't interchange).
 */
function shopify_graphql_delete_redirect(string $shop_url, string $token, string $gid): array
{
    $mutation = <<<'GQL'
    mutation urlRedirectDelete($id: ID!) {
      urlRedirectDelete(id: $id) {
        deletedUrlRedirectId
        userErrors { field message }
      }
    }
    GQL;
    $r = shopify_graphql_call($shop_url, $token, $mutation, ['id' => $gid]);
    if ($r['error'] && !$r['data']) return ['success' => false, 'error' => $r['error']];
    $payload = $r['data']['urlRedirectDelete'] ?? [];
    if (!empty($payload['userErrors'])) {
        return ['success' => false, 'error' => $payload['userErrors'][0]['message'] ?? 'userError'];
    }
    if (!empty($payload['deletedUrlRedirectId'])) return ['success' => true];
    return ['success' => false, 'error' => 'no_id_returned'];
}
