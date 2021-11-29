<?php

require __DIR__ . '/vendor/autoload.php';

use Github\Client;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Signer\Key\LocalFileReference;
use Lcobucci\JWT\Signer\Rsa\Sha256;

// github app id
define('APP_ID', 'XXXXXX');
// app installation id
define('INSTALL_ID', 'XXXXXXXX');

// destination organization name
define('REPO_OWNER', 'flavioheleno');
// destination repository name
define('REPO_NAME', 'bugs.php.net');

// first BugId (inclusive)
define('MIN_BUGID', 1);
// last BugId (exclusive)
define('MAX_BUGID', 200);

// save a copy of BUG_URL locally so we don't have to hit it
define('LOCAL_CACHE', true);

// github app private key
define('PRIVKEY_PATH', __DIR__ . '/private-key.pem');
// local copy of access token (avoids generating a new token everytime the script is executed)
define('TOKEN_PATH', __DIR__ . '/token.json');
// path to content cache
define('CACHE_PATH', __DIR__ . '/cache/bug-%d.html');
// url to bugs.php.net
define('BUG_URL', 'https://bugs.php.net/bug.php?id=%d');

// prints some additional log messages
define('DEBUG_MODE', false);

function curlGet(string $url): string {
  $curl = curl_init();

  curl_setopt_array(
    $curl,
    [
      CURLOPT_AUTOREFERER    => true,
      CURLOPT_COOKIEFILE     => '',
      CURLOPT_CUSTOMREQUEST  => 'GET',
      CURLOPT_FAILONERROR    => true,
      CURLOPT_FOLLOWLOCATION => true,
      // CURLOPT_HEADER         => true,
      CURLOPT_MAXREDIRS      => 10,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT        => 30,
      CURLOPT_URL            => $url,
      CURLOPT_USERAGENT      => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:94.0) Gecko/20100101 Firefox/94.0'
    ]
  );

  $response = curl_exec($curl);
  $err = curl_error($curl);

  curl_close($curl);

  if ($err !== '') {
    throw new RuntimeExceptio(
      sprintf(
        'cURL Error #:%s',
        $err
      )
    );
  }

  return $response;
}

function getElementValue(DOMXPath $dom, string $query, int $rootId, string $default = ''): string {
  $nodeList = $dom->query($query);
  if (count($nodeList) <= $rootId) {
    return $default;
  }

  $value = trim((string)$nodeList[$rootId]->nodeValue);
  if ($value === '') {
    return $default;
  }

  return $value;
}

function getElementChildValue(DOMXPath $dom, string $query, int $rootId, int $childId, string $default = ''): string {
  $nodeList = $dom->query($query);
  if (count($nodeList) <= $rootId) {
    return $default;
  }

  $element = $nodeList[$rootId];
  if (count($element->childNodes) <= $childId) {
    return $default;
  }

  $value = trim((string)$element->childNodes[$childId]->nodeValue);
  if ($value === '') {
    return $default;
  }

  return $value;
}

$client = new Client();

$accessToken = null;

$now = new DateTimeImmutable();

if (is_readable(TOKEN_PATH)) {
  $accessToken = json_decode(file_get_contents(TOKEN_PATH), true);
  if ($accessToken !== null && isset($accessToken['expires_at']) === true) {
    $expiresAt = new DateTimeImmutable($accessToken['expires_at']);
    if ($expiresAt < $now) {
      $accessToken = null;
    }
  }
}

if ($accessToken === null) {
  if (is_readable(PRIVKEY_PATH) === false) {
    echo 'Private key file is not readable!', PHP_EOL;

    exit(1);
  }

  $config = Configuration::forSymmetricSigner(
    new Sha256(),
    LocalFileReference::file(PRIVKEY_PATH)
  );

  $jwt = $config->builder(ChainedFormatter::withUnixTimestampDates())
    ->issuedBy(APP_ID)
    ->issuedAt($now)
    ->expiresAt($now->modify('+1 minute'))
    ->getToken($config->signer(), $config->signingKey());

  $client->authenticate($jwt->toString(), null, Client::AUTH_JWT);

  echo 'Generating an installation access token', PHP_EOL;
  $accessToken = $client->api('apps')->createInstallationToken(INSTALL_ID);
  if (isset($accessToken['token']) === false) {
    echo 'Failed to get an installation access token!', PHP_EOL;

    exit(1);
  }

  file_put_contents(TOKEN_PATH, json_encode($accessToken));
}

$client->authenticate($accessToken['token'], null, Client::AUTH_ACCESS_TOKEN);

if (LOCAL_CACHE) {
  $cachePath = dirname(CACHE_PATH);
  if (is_dir($cachePath) === false) {
    if (mkdir($cachePath, recursive: true) === false) {
      echo 'Failed to create cache directory!', PHP_EOL;

      exit(1);
    }
  }
}

for ($bugId = MIN_BUGID; $bugId < MAX_BUGID; $bugId++) {
  echo 'Retrieving bug id #', $bugId, PHP_EOL;

  $cacheFile = sprintf(CACHE_PATH, $bugId);
  $bugUrl    = sprintf(BUG_URL, $bugId);

  $response = null;
  if (LOCAL_CACHE === true && file_exists($cacheFile) === true) {
    if (DEBUG_MODE) {
      echo ' > Loading cached content', PHP_EOL;
    }

    $response = file_get_contents($cacheFile) ?: null;
  }

  if ($response === null) {
    try {
      if (DEBUG_MODE) {
        echo ' > Retrieving ', $bugUrl, PHP_EOL;
      }

      $response = curlGet($bugUrl);

      if (LOCAL_CACHE === true) {
        file_put_contents($cacheFile, $response);
      }
    } catch (Exception $exception) {
      echo ' > Exception: ', $exception->getMessage(), PHP_EOL;

      continue;
    }
  }

  if ($response === '') {
    echo ' > Fatal error: EMPTY RESPONSE!', PHP_EOL;

    exit(1);
  }

  $doc = new DOMDocument('1.0', 'UTF-8');
  @$doc->loadHTML($response);

  $dom = new DOMXPath($doc);

  $xpath = [
    'number'         => '//*[@id="number"]',
    'summary'        => '//*[@id="summary"]',
    'submission'     => '//*[@id="submission"]',
    'submitter'      => '//*[@id="submitter"]',
    'categorization' => '//*[@id="categorization"]',
    'situation'      => '//*[@id="situation"]',
    'private'        => '//*[@id="private"]',
    'content'        => '/html/body/table[2]/tr/td/div[4]/pre'
  ];

  try {
    // $title       = trim($dom->query($xpath['summary'])[0]->nodeValue);
    $title       = getElementValue($dom, $xpath['summary'], 0, '(unknown title)');
    // $content     = trim($dom->query($xpath['content'])[0]->nodeValue);
    $content     = getElementValue($dom, $xpath['content'], 0, '(unknown content)');
    // $submittedBy = trim($dom->query($xpath['submitter'])[0]->childNodes[3]->nodeValue);
    $submittedBy = getElementChildValue($dom, $xpath['submitter'], 0, 3, '(unknown submitter)');
    // $submittedIn = trim($dom->query($xpath['submission'])[0]->childNodes[3]->nodeValue);
    $submittedIn = getElementChildValue($dom, $xpath['submission'], 0, 3, '(unknown submission timestamp)');
    // $status      = trim($dom->query($xpath['categorization'])[0]->childNodes[3]->nodeValue);
    $status      = getElementChildValue($dom, $xpath['categorization'], 0, 3, '(unknown status)');

    $body = sprintf(
      <<<EOL
      %s

      **Submitted by:** %s
      **Submitted in:** %s
      **Status:** %s
      EOL,
      $content,
      $submittedBy,
      $submittedIn,
      $status
    );

    $issue = $client
      ->api('issue')
      ->create(
        REPO_OWNER,
        REPO_NAME,
        [
          'title' => $title,
          'body'  => $body
        ]
      );

    if (strtolower($status) === 'closed') {
      if (DEBUG_MODE) {
        echo ' > Closing issue ', $issue['number'], PHP_EOL;
      }

      $client
        ->api('issue')
        ->update(
          REPO_OWNER,
          REPO_NAME,
          $issue['number'],
          ['state' => 'closed']
        );

        sleep(1);
    }

    sleep(5);
  } catch (Exception $exception) {
    echo ' > Exception: ', $exception->getMessage(), PHP_EOL;
    if (DEBUG_MODE) {
      echo $exception->getFile(), ':', $exception->getLine(), PHP_EOL;
      echo $exception->getTraceAsString(), PHP_EOL;
    }
  }
}
