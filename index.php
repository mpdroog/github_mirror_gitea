<?php
require __DIR__ . "/_init.php";
require __DIR__ . "/_config.php";

$options = getopt("v");
define("VERBOSE", isset($options["v"]));

class Github {
  public static $ch = null;

  public static function call($method, $url, $args = []) {
    $ch = self::$ch;
    $headers = [
        'User-Agent: github.com/mpdroog',
        "Time-Zone: Europe/Amsterdam",
        'Accept: application/vnd.github.v3+json',
        'Content-Type: application/json',
        sprintf('Authorization: token %s', GITHUB_TOKEN)
    ];
    $res_headers = [];

    curl_setopt($ch, CURLOPT_URL, sprintf("%s%s", GITHUB_URL, $url));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) use (&$res_headers) {
        $len = strlen($header);
        $header = explode(':', $header, 2);
        if (count($header) < 2) // ignore invalid headers
          return $len;
        $name = strtolower(trim($header[0]));
        if (!array_key_exists($name, $res_headers))
          $res_headers[$name] = [trim($header[1])];
        else
          $res_headers[$name][] = trim($header[1]);
        return $len;
    });

    if (count($args) > 0) {
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($args));
    }
    $res = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

    if ($http < 200 || $http > 299) {
        user_error(sprintf("HTTP(%s %s) => (%d) %s", $method, $url, $http, curl_error($ch)));
    }

    $mime_json = "application/json";
    if (substr($contentType, 0, strlen($mime_json)) === $mime_json) {
        $res = json_decode($res, true);
        return ["body" => $res, "header" => $res_headers];
    }

    var_dump($http, $res_headers, $res);
    trigger_error("Unexpected server response.content-type=$contentType");
    return false;
  }
}

class Gitea {
  public static $ch = null;

  public static function call($method, $url, $args = []) {
    $ch = self::$ch;
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        sprintf('Authorization: token %s', GITEA_TOKEN)
    ];
    $res_headers = [];

    curl_setopt($ch, CURLOPT_URL, sprintf("%s%s", GITEA_URL, $url));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) use (&$res_headers) {
        $len = strlen($header);
        $header = explode(':', $header, 2);
        if (count($header) < 2) // ignore invalid headers
          return $len;
        $name = strtolower(trim($header[0]));
        if (!array_key_exists($name, $res_headers))
          $res_headers[$name] = [trim($header[1])];
        else
          $res_headers[$name][] = trim($header[1]);
        return $len;
    });

    if (count($args) > 0) {
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($args));
    }
    $res = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

    if ($http < 200 || $http > 299) {
        //user_error(sprintf("HTTP(%s %s) => (%d) %s", $method, $url, $http, curl_error($ch)));
    }

    $mime_json = "application/json";
    if (substr($contentType, 0, strlen($mime_json)) === $mime_json) {
        $res = json_decode($res, true);
        return ["http" => $http, "body" => $res, "header" => $res_headers];
    }
    //var_dump($http, $res_headers, $res, $url);
    //trigger_error("Unexpected server response.content-type=$contentType");
    return [
      "http" => $http, "body" => $res, "header" => $res_headers
    ];
  }
}

// One curl instance for all future calls
Github::$ch = curl_init();
if (Github::$ch === false) {
    user_error("Github::curl_init fail");
}
Gitea::$ch = curl_init();
if (Gitea::$ch === false) {
    user_error("Gitea::curl_init fail");
}

// List repositories that the authenticated user has explicit permission (:read, :write, or :admin) to access.
// https://developer.github.com/v3/repos/#list-your-repositories

$next = "/user/repos";
while ($next) {
  $repos = Github::call("GET", $next); //?per_page=100");

  $next = false;
  if (isset($repos["header"]["link"])) {
    // Iterate on link-header(s)
    foreach ($repos["header"]["link"] as $link) {
      // Iterate on rel-types
      foreach (explode(",", $link) as $fwd) {
        // <https://api.github.com/user/repos?page=2>; rel="next"
        $rel = 'rel="next"';
        if (substr($fwd, strlen($fwd)-strlen($rel)) === $rel) {
          $next = explode(";", $fwd)[0];             // strip rel off
          $next = substr($next, 1, strlen($next)-2); // strip <..> off
          $next = substr($next, strpos($next, ".com")+strlen(".com")); // strip github-url off
          if (VERBOSE) echo "pagination.next=$next\n";
        }
      }
    }
  }

  foreach ($repos["body"] as $repo) {
    if (VERBOSE) echo sprintf("github.com/%s private=%s fork=%s", $repo["full_name"], $repo["private"], $repo["fork"]);
    $ignore = false;
    foreach ($repo_ignore as $ign) {
      if (substr($repo["full_name"], 0, strlen($ign)) === $ign) {
        if (VERBOSE) echo " ignored!\n";
        $ignore = true;
        continue;
      }
    }
    if ($ignore) continue;

    $res = Gitea::call("POST", "/api/v1/repos/migrate", [
      "clone_addr" => $repo["html_url"], //"https://github.com/".$repo["full_name"],
      "mirror" => true,
      "private" => $repo["private"],
      "description" => $repo["description"],
      "repo_name" => $repo["name"],
      "uid" => USER,
      "auth_username" => GITHUB_USER,
      "auth_password" => GITHUB_PASS
    ]);

    if ($res["http"] === 201) {
      if (VERBOSE) echo " added\n";
      continue;
    }
    if ($res["http"] === 403) {
      echo " invalid key\n";
      echo sprintf("ERROR: Invalid GITEA_TOKEN(%s), please update and try again!\n", GITEA_TOKEN);
      die();
    }

    if ($res["http"] === 409) {
      if (VERBOSE) echo " exists, calling mirror-sync";

      $res = Gitea::call("POST", sprintf("/api/v1/repos/%s/%s/mirror-sync", OWNER, $repo["name"]));
      if ($res["http"] === 200) {
        if (VERBOSE) echo " done!\n";
        continue;
      }
    }

    echo " ERROR\n";
    var_dump($repo);
    var_dump($res);
    echo "\n";
  }
}
