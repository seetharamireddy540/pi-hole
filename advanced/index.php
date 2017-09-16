<?php
/* Pi-hole: A black hole for Internet advertisements
*  (c) 2017 Pi-hole, LLC (https://pi-hole.net)
*  Network-wide ad blocking via your own hardware.
*
*  This file is copyright under the latest version of the EUPL.
*  Please see LICENSE file for your rights under this license. */

// Sanitise HTTP_HOST output
$serverName = htmlspecialchars($_SERVER["HTTP_HOST"]);

// Get values from setupVars.conf
if (is_file("/etc/pihole/setupVars.conf")) {
    $setupVars = parse_ini_file("/etc/pihole/setupVars.conf");
    $svFQDN = $setupVars["FQDN"];
    $svPasswd = !empty($setupVars["WEBPASSWORD"]);
    $svEmail = (!empty($setupVars["ADMIN_EMAIL"]) && filter_var($setupVars["ADMIN_EMAIL"], FILTER_VALIDATE_EMAIL)) ? $setupVars["ADMIN_EMAIL"] : "";
    unset($setupVars);
} else {
    die("[ERROR] File not found: <code>/etc/pihole/setupVars.conf</code>");
}

// Set landing page location, found within /var/www/html/
$landPage = "../landing.php";

// Set empty array for hostnames to be accepted as self address for splash page
$authorizedHosts = [];

// Append FQDN to $authorizedHosts
if (!empty($svFQDN)) array_push($authorizedHosts, $svFQDN);

// Append virtual hostname to $authorizedHosts
if (!empty($_SERVER["VIRTUAL_HOST"])) {
    array_push($authorizedHosts, $_SERVER["VIRTUAL_HOST"]);
}

// Set which extension types render as Block Page (Including "" for index.wxyz)
$validExtTypes = array("asp", "htm", "html", "php", "rss", "xml", "");

// Get extension of current URL
$currentUrlExt = pathinfo($_SERVER["REQUEST_URI"], PATHINFO_EXTENSION);

// Check if this is served over HTTP or HTTPS
if(isset($_SERVER['HTTPS'])) {
    if ($_SERVER['HTTPS'] == "on") {
        $proto = "https";
    } else {
        $proto = "http";
    }
}

// Set mobile friendly viewport
$viewPort = '<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1"/>';

// Set response header
function setHeader($type = "x") {
    header("X-Pi-hole: A black hole for Internet advertisements.");
    if (isset($type) && $type === "js") header("Content-Type: application/javascript");
}

// Determine block page redirect type
if ($serverName === "pi.hole") {
    exit(header("Location: /admin"));
} elseif (filter_var($serverName, FILTER_VALIDATE_IP) || in_array($serverName, $authorizedHosts)) {
    // Set Splash Page output
    $splashPage = "
    <html><head>
        $viewPort
        <link rel='stylesheet' href='/pihole/blockingpage.css' type='text/css'/>
    </head><body id='splashpage'><img src='/admin/img/logo.svg'/><br/>Pi-<b>hole</b>: Your black hole for Internet advertisements</body></html>
    ";

    // Render splash page or landing page when directly browsing via IP or auth'd hostname
    $renderPage = is_file(getcwd()."/$landPage") ? include $landPage : "$splashPage";
    unset($serverName, $svFQDN, $svPasswd, $svEmail, $authorizedHosts, $validExtTypes, $currentUrlExt, $viewPort);
    exit($renderPage);
} elseif ($currentUrlExt === "js") {
    // Serve dummy Javascript for blocked domains
    exit(setHeader("js").'var x = "Pi-hole: A black hole for Internet advertisements."');
} elseif (strpos($_SERVER["REQUEST_URI"], "?") !== FALSE && isset($_SERVER["HTTP_REFERER"])) {
    // Serve blank image upon receiving REQUEST_URI w/ query string & HTTP_REFERRER (e.g: an iframe of a blocked domain)
    exit(setHeader().'<html>
        <head><script>window.close();</script></head>
        <body><img src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACwAAAAAAQABAAACAkQBADs="></body>
    </html>');
} elseif (!in_array($currentUrlExt, $validExtTypes) || substr_count($_SERVER["REQUEST_URI"], "?")) {
    // Serve SVG upon receiving non $validExtTypes URL extension or query string (e.g: not an iframe of a blocked domain)
    $blockImg = '<a href="/"><svg xmlns="http://www.w3.org/2000/svg" width="110" height="16"><defs><style>a {text-decoration: none;} circle {stroke: rgba(152,2,2,0.5); fill: none; stroke-width: 2;} rect {fill: rgba(152,2,2,0.5);} text {opacity: 0.3; font: 11px Arial;}</style></defs><circle cx="8" cy="8" r="7"/><rect x="10.3" y="-6" width="2" height="12" transform="rotate(45)"/><text x="19.3" y="12">Blocked by Pi-hole</text></svg></a>';
    exit(setHeader()."<html>
        <head>$viewPort</head>
        <body>$blockImg</body>
    </html>");
}

/* Start processing Block Page from here */

// Determine placeholder text based off $svPasswd presence
$wlPlaceHolder = empty($svPasswd) ? "No admin password set" : "Javascript disabled";

// Define admin email address text
$bpAskAdmin = !empty($svEmail) ? '<a href="mailto:'.$svEmail.'?subject=Site Blocked: '.$serverName.'"></a>' : "<span/>";

// Determine if at least one block list has been generated
if (empty(glob("/etc/pihole/list.0.*.domains")))
    die("[ERROR] There are no domain lists generated lists within <code>/etc/pihole/</code>! Please update gravity by running <code>pihole -g</code>, or repair Pi-hole using <code>pihole -r</code>.");

// Set location of adlists file
if (is_file("/etc/pihole/adlists.list")) {
    $adLists = "/etc/pihole/adlists.list";
} elseif (is_file("/etc/pihole/adlists.default")) {
    $adLists = "/etc/pihole/adlists.default";
} else {
    die("[ERROR] File not found: <code>/etc/pihole/adlists.list</code>");
}

// Get all URLs starting with "http" or "www" from adlists and re-index array numerically
$adlistsUrls = array_values(preg_grep("/(^http)|(^www)/i", file($adLists, FILE_IGNORE_NEW_LINES)));

if (empty($adlistsUrls))
    die("[ERROR]: There are no adlist URL's found within <code>$adLists</code>");

// Get total number of blocklists (Including Whitelist, Blacklist & Wildcard lists)
$adlistsCount = count($adlistsUrls) + 3;

// Get results of queryads.php exact search
ini_set("default_socket_timeout", 3);
function queryAds($serverName) {
    // Determine the time it takes while querying adlists
    $preQueryTime = microtime(true)-$_SERVER["REQUEST_TIME_FLOAT"];
    $queryAds = file("http://127.0.0.1/admin/scripts/pi-hole/php/queryads.php?domain=$serverName&bp", FILE_IGNORE_NEW_LINES);
    $queryAds = array_values(array_filter(preg_replace("/data:\s+/", "", $queryAds)));
    $queryTime = sprintf("%.0f", (microtime(true)-$_SERVER["REQUEST_TIME_FLOAT"]) - $preQueryTime);

    // Exception Handling
    try {
        if ($queryTime >= ini_get("default_socket_timeout")) {
            throw new Exception ("Connection timeout (".ini_get("default_socket_timeout")."s)");
        } elseif (!strpos($queryAds[0], ".") !== false) {
            if (strpos($queryAds[0], "No exact results") !== FALSE) return array("0" => "none");
            throw new Exception ("Unhandled error message (<code>$queryAds[0]</code>)");
        }
        return $queryAds;
    } catch (Exception $e) {
        return array("0" => "error", "1" => $e->getMessage());
    }

}

$queryAds = queryAds($serverName);

if ($queryAds[0] === "error") {
    die("[ERROR]: Unable to parse results from <i>queryads.php</i>: <code>".$queryAds[1]."</code>");
} else {
    $featuredTotal = count($queryAds);

    // Place results into key => value array
    $queryResults = null;
    foreach ($queryAds as $str) {
      $value = explode(" ", $str);
      @$queryResults[$value[0]] .= "$value[1]";
    }
}

// Determine if domain has been blacklisted, whitelisted, wildcarded or CNAME blocked
if (strpos($queryAds[0], "blacklist") !== FALSE) {
    $notableFlagClass = "blacklist";
    $adlistsUrls = array("π" => substr($queryAds[0], 2));
} elseif (strpos($queryAds[0], "whitelist") !== FALSE) {
    $notableFlagClass = "noblock";
    $adlistsUrls = array("π" => substr($queryAds[0], 2));
    $wlInfo = "recentwl";
} elseif (strpos($queryAds[0], "wildcard") !== FALSE) {
    $notableFlagClass = "wildcard";
    $adlistsUrls = array("π" => substr($queryAds[0], 2));
} elseif ($queryAds[0] === "none") {
    $featuredTotal = "0";
    $notableFlagClass = "noblock";

    // Determine appropriate info message if CNAME exists
    $dnsRecord = dns_get_record("$serverName")[0];
    if (array_key_exists("target", $dnsRecord)) {
        $wlInfo = $dnsRecord['target'];
    } else {
        $wlInfo = "unknown";
    }
}

// Set #bpOutput notification
$wlOutputClass = (isset($wlInfo) && $wlInfo === "recentwl") ? $wlInfo : "hidden";
$wlOutput = (isset($wlInfo) && $wlInfo !== "recentwl") ? "<a href='http://$wlInfo'>$wlInfo</a>" : "";

// Get Pi-hole Core version
$phVersion = exec("cd /etc/.pihole/ && git describe --long --tags");

// Print $execTime on development branches
// Marginally faster than "git rev-parse --abbrev-ref HEAD"
if (explode("-", $phVersion)[1] != "0")
  $execTime = microtime(true)-$_SERVER["REQUEST_TIME_FLOAT"];
?>
<!DOCTYPE html>
<!-- Pi-hole: A black hole for Internet advertisements
*  (c) 2017 Pi-hole, LLC (https://pi-hole.net)
*  Network-wide ad blocking via your own hardware.
*
*  This file is copyright under the latest version of the EUPL. -->
<html>
<head>
  <meta charset="UTF-8">
  <?=$viewPort ?>
  <?=setHeader() ?>
  <meta name="robots" content="noindex,nofollow"/>
  <meta http-equiv="x-dns-prefetch-control" content="off">
  <link rel="shortcut icon" href="<?php echo $proto; ?>://pi.hole/admin/img/favicon.png" type="image/x-icon"/>
  <link rel="stylesheet" href="<?php echo $proto; ?>://pi.hole/pihole/blockingpage.css" type="text/css"/>
  <title>● <?=$serverName ?></title>
  <script src="<?php echo $proto; ?>://pi.hole/admin/scripts/vendor/jquery.min.js"></script>
  <script>
    window.onload = function () {
      <?php
      // Remove href fallback from "Back to safety" button
      if ($featuredTotal > 0) echo '$("#bpBack").removeAttr("href");';
      // Enable whitelisting if $svPasswd is present & JS is available
      if (!empty($svPasswd) && $featuredTotal > 0) {
          echo '$("#bpWLPassword, #bpWhitelist").prop("disabled", false);';
          echo '$("#bpWLPassword").attr("placeholder", "Password");';
      }
      ?>
    }
  </script>
</head>
<body id="blockpage"><div id="bpWrapper">
<header>
  <h1 id="bpTitle">
    <a class="title" href="/"><?php //Website Blocked ?></a>
  </h1>
  <div class="spc"></div>

  <input id="bpAboutToggle" type="checkbox"/>
  <div id="bpAbout">
    <div class="aboutPH">
      <div class="aboutImg"/></div>
      <p>Open Source Ad Blocker
        <small>Designed for Raspberry Pi</small>
      </p>
    </div>
    <div class="aboutLink">
      <a class="linkPH" href="https://github.com/pi-hole/pi-hole/wiki/What-is-Pi-hole%3F-A-simple-explanation"><?php //About PH ?></a>
      <?php if (!empty($svEmail)) echo '<a class="linkEmail" href="mailto:'.$svEmail.'"></a>'; ?>
    </div>
  </div>

  <div id="bpAlt">
    <label class="altBtn" for="bpAboutToggle"><?php //Why am I here? ?></label>
  </div>
</header>

<main>
  <div id="bpOutput" class="<?=$wlOutputClass ?>"><?=$wlOutput ?></div>
  <div id="bpBlock">
    <p class="blockMsg"><?=$serverName ?></p>
  </div>
  <?php if(isset($notableFlagClass)) { ?>
    <div id="bpFlag">
        <p class="flagMsg <?=$notableFlagClass ?>"></p>
    </div>
  <?php } ?>
  <div id="bpHelpTxt"><?=$bpAskAdmin ?></div>
  <div id="bpButtons" class="buttons">
    <a id="bpBack" onclick="javascript:history.back()" href="about:home"></a>
    <?php if ($featuredTotal > 0) echo '<label id="bpInfo" for="bpMoreToggle"></label>'; ?>
  </div>
  <input id="bpMoreToggle" type="checkbox">
  <div id="bpMoreInfo">
    <span id="bpFoundIn"><span><?=$featuredTotal ?></span><?=$adlistsCount ?></span>
    <pre id='bpQueryOutput'><?php if ($featuredTotal > 0) foreach ($queryResults as $num => $value) { echo "<span>[$num]:</span>$adlistsUrls[$num]\n"; } ?></pre>

    <form id="bpWLButtons" class="buttons">
      <input id="bpWLDomain" type="text" value="<?=$serverName ?>" disabled/>
      <input id="bpWLPassword" type="password" placeholder="<?=$wlPlaceHolder ?>" disabled/><button id="bpWhitelist" type="button" disabled></button>
    </form>
  </div>
</main>

<footer><span><?=date("l g:i A, F dS"); ?>.</span> Pi-hole <?=$phVersion ?> (<?=gethostname()."/".$_SERVER["SERVER_ADDR"]; if (isset($execTime)) printf("/%.2fs", $execTime); ?>)</footer>
</div>

<script>
  function add() {
    $("#bpOutput").removeClass("hidden error exception");
    $("#bpOutput").addClass("add");
    var domain = "<?=$serverName ?>";
    var pw = $("#bpWLPassword");
    if(domain.length === 0) {
      return;
    }
    $.ajax({
      url: "/admin/scripts/pi-hole/php/add.php",
      method: "post",
      data: {"domain":domain, "list":"white", "pw":pw.val()},
      success: function(response) {
        if(response.indexOf("Pi-hole blocking") !== -1) {
          setTimeout(function(){window.location.reload(1);}, 10000);
          $("#bpOutput").removeClass("add");
          $("#bpOutput").addClass("success");
        } else {
          $("#bpOutput").removeClass("add");
          $("#bpOutput").addClass("error");
          $("#bpOutput").html(""+response+"");
        }
      },
      error: function(jqXHR, exception) {
        $("#bpOutput").removeClass("add");
        $("#bpOutput").addClass("exception");
      }
    });
  }
  <?php if ($featuredTotal > 0) { ?>
    $(document).keypress(function(e) {
        if(e.which === 13 && $("#bpWLPassword").is(":focus")) {
            add();
        }
    });
    $("#bpWhitelist").on("click", function() {
        add();
    });
  <?php } ?>
</script>
</body></html>
