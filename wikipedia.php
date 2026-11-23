<?php
namespace MedicineScanner;

function wikipedia_request(array $params): array
{
  if (!function_exists('curl_init')) {
    return [null, 'PHP cURL is not enabled.'];
  }

  $url = 'https://en.wikipedia.org/w/api.php?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 4,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
    CURLOPT_USERAGENT => 'MedicineScanner/1.1 (PHP Wikipedia chatbot)',
  ]);
  $body = curl_exec($ch);
  $error = curl_error($ch);
  $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($body === false) {
    return [null, $error ?: 'Could not reach Wikipedia.'];
  }
  if ($status < 200 || $status >= 300) {
    return [null, 'Wikipedia returned HTTP ' . $status . '.'];
  }

  $data = json_decode($body, true);
  if (!is_array($data)) {
    return [null, 'Wikipedia returned an invalid response.'];
  }
  return [$data, null];
}

function wikipedia_search($question): array
{
  $question = trim(preg_replace('/\s+/', ' ', strip_tags((string) $question)));
  if ($question === '' || mb_strlen($question) > 600) {
    return ['status' => 'invalid', 'error' => 'Enter a question of 1 to 600 characters.', 'articles' => []];
  }
  $searchQuery = preg_replace('/^(what is|what are|who is|who was|who developed|when was|where is|explain|describe|define|tell me about)\s+/i', '', $question);
  $searchQuery = preg_replace('/^(the|a|an)\s+/i', '', $searchQuery);
  $searchQuery = trim($searchQuery, " \t\n\r\0\x0B?.!");
  if ($searchQuery === '') {
    $searchQuery = $question;
  }
  $lowerQuery = mb_strtolower($searchQuery);
  if (strpos($lowerQuery, 'cybersecurity') !== false) {
    $searchQuery .= ' computer security';
  } elseif (strpos($lowerQuery, 'database management system') !== false) {
    $searchQuery .= ' DBMS';
  }

  $cacheKey = hash('sha256', mb_strtolower($question));
  if (session_status() === PHP_SESSION_ACTIVE) {
    $cache = $_SESSION['wiki_cache'] ?? [];
    foreach ($cache as $key => $entry) {
      if (!is_array($entry) || ($entry['expires'] ?? 0) <= time()) {
        unset($cache[$key]);
      }
    }
    $_SESSION['wiki_cache'] = $cache;
    if (isset($cache[$cacheKey])) {
      return $cache[$cacheKey]['result'];
    }

    $lastSearch = (float) ($_SESSION['wiki_last_search'] ?? 0);
    if (microtime(true) - $lastSearch < 1.0) {
      return ['status' => 'rate_limited', 'error' => 'Please wait one second before searching Wikipedia again.', 'articles' => []];
    }
    $_SESSION['wiki_last_search'] = microtime(true);
  }

  $store = function (array $result, $ttl = 300) use ($cacheKey) {
    if (session_status() === PHP_SESSION_ACTIVE) {
      $_SESSION['wiki_cache'][$cacheKey] = ['expires' => time() + $ttl, 'result' => $result];
      while (count($_SESSION['wiki_cache']) > 5) {
        array_shift($_SESSION['wiki_cache']);
      }
    }
    return $result;
  };

  [$search, $searchError] = wikipedia_request([
    'action' => 'query',
    'list' => 'search',
    'srsearch' => $searchQuery,
    'srlimit' => 5,
    'srprop' => 'snippet',
    'format' => 'json',
  ]);
  if ($searchError || !is_array($search)) {
    return $store(['status' => 'unavailable', 'error' => $searchError ?: 'Wikipedia returned an invalid search response.', 'articles' => []], 15);
  }

  $rawHits = array_slice($search['query']['search'] ?? [], 0, 5);
  if (!$rawHits) {
    return $store(['status' => 'none', 'error' => 'Wikipedia found no matching articles.', 'articles' => []], 120);
  }

  $terms = preg_split('/[^a-z0-9]+/', mb_strtolower($searchQuery), -1, PREG_SPLIT_NO_EMPTY);
  $stopWords = ['the', 'and', 'for', 'with', 'from', 'what', 'when', 'where', 'which', 'who', 'was', 'were', 'are', 'is', 'how', 'does', 'did', 'can', 'could'];
  $terms = array_values(array_unique(array_filter($terms, fn($term) => strlen($term) > 2 && !in_array($term, $stopWords, true))));
  $rankedHits = [];
  foreach ($rawHits as $position => $hit) {
    $titleLower = mb_strtolower((string) ($hit['title'] ?? ''));
    $snippet = html_entity_decode(strip_tags((string) ($hit['snippet'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $snippetLower = mb_strtolower($snippet);
    $score = $searchQuery !== '' && strpos($titleLower, mb_strtolower($searchQuery)) !== false ? 10 : 0;
    foreach ($terms as $term) {
      if (strpos($titleLower, $term) !== false) {
        $score += 2;
      } elseif (strpos($snippetLower, $term) !== false) {
        $score++;
      }
    }
    if ($score > 0) {
      $hit['_score'] = $score;
      $hit['_position'] = $position;
      $rankedHits[] = $hit;
    }
  }
  usort($rankedHits, function ($a, $b) {
    return ($b['_score'] <=> $a['_score']) ?: ($a['_position'] <=> $b['_position']);
  });
  $hits = array_slice($rankedHits, 0, 3);
  if (!$hits) {
    return $store(['status' => 'none', 'error' => 'Wikipedia found no sufficiently relevant articles.', 'articles' => []], 120);
  }

  $pageIds = [];
  foreach ($hits as $hit) {
    $pageId = (int) ($hit['pageid'] ?? 0);
    if ($pageId > 0 && !in_array($pageId, $pageIds, true)) {
      $pageIds[] = $pageId;
    }
  }
  if (!$pageIds) {
    return $store(['status' => 'none', 'error' => 'Wikipedia search returned no article identifiers.', 'articles' => []], 120);
  }

  [$details, $detailsError] = wikipedia_request([
    'action' => 'query',
    'prop' => 'extracts|info',
    'exintro' => 1,
    'explaintext' => 1,
    'inprop' => 'url',
    'pageids' => implode('|', $pageIds),
    'format' => 'json',
  ]);
  if ($detailsError || !is_array($details)) {
    return $store(['status' => 'unavailable', 'error' => $detailsError ?: 'Wikipedia returned an invalid article response.', 'articles' => []], 15);
  }

  $pages = $details['query']['pages'] ?? [];
  $articles = [];
  foreach ($hits as $hit) {
    $page = $pages[(string) ($hit['pageid'] ?? '')] ?? null;
    if (!is_array($page)) {
      continue;
    }
    $extract = trim($page['extract'] ?? '');
    if ($extract === '') {
      continue;
    }

    $title = (string) ($page['title'] ?? $hit['title'] ?? '');
    $url = (string) ($page['fullurl'] ?? '');
    if (strpos($url, 'https://en.wikipedia.org/') !== 0) {
      $url = 'https://en.wikipedia.org/wiki/' . rawurlencode(str_replace(' ', '_', $title));
    }
    $description = html_entity_decode(strip_tags((string) ($hit['snippet'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $description = trim(preg_replace('/\s+/', ' ', $description));

    $articles[] = [
      'ref' => 'W' . (count($articles) + 1),
      'title' => $title,
      'url' => $url,
      'description' => mb_substr($description, 0, 300),
      'extract' => mb_substr($extract, 0, 1600),
    ];
  }

  if (!$articles) {
    return $store(['status' => 'empty', 'error' => 'Wikipedia articles had no readable introductory extracts.', 'articles' => []], 120);
  }
  return $store(['status' => 'ok', 'articles' => $articles], 600);
}
