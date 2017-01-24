<?php
#
# Copyright (c) 2016 Jeffrey M. Squyres.  All rights reserved.
# $COPYRIGHT$
#
# Additional copyrights may follow
#
# $HEADER$
#

##############################################################################
##############################################################################
# Fill in your configuration values in the
# verify-signed-off-by-config.inc file
##############################################################################
##############################################################################

if (!is_file("verify-signed-off-by-config.inc")) {
    my_die("Cannot find verify-signed-by-off.php's config file.");
}
require_once "verify-signed-off-by-config.inc";

##############################################################################
##############################################################################
# You should not need to change below this line
##############################################################################
##############################################################################

# For 4.3.0 <= PHP <= 5.4.0
if (!function_exists('http_response_code')) {
    function http_response_code($newcode = NULL) {
        static $code = 200;
        if ($newcode !== NULL) {
            header("X-PHP-Response-Code: $newcode", true, $newcode);
            if (!headers_sent()) {
                $code = $newcode;
            }
        }
        return $code;
    }
}

function my_die($msg, $code = 400)
{
    # Die with a non-200 error code
    http_response_code($code);

    die($msg);
}

function debug($config, $str)
{
    if (isset($config["debug"]) && $config["debug"]) {
        print($str);
    }
}

##############################################################################

function check_for_allowed_sources($config)
{
    global $config;

    if (isset($config["allowed_sources"]) &&
        count($config["allowed_sources"] > 0)) {
	if (isset($_SERVER["HTTP_X_REAL_IP"])) {
            $source = ip2long($_SERVER["HTTP_X_REAL_IP"]);
        } else if (isset($_SERVER["REMOTE_ADDR"])) {
            $source = ip2long($_SERVER["REMOTE_ADDR"]);
        } else {
            # This will not match anything
            $source = 0;
        }

        $happy = 0;
        foreach ($config["allowed_sources"] as $cidr) {
            $parts = explode('/', $cidr);
            $value = ip2long($parts[0]);
            $mask = (pow(2, 33) - 1) - (pow(2, $parts[1] + 1) - 1);

            if (($value & $mask) == ($source & $mask)) {
                $happy = 1;
            }
        }
        if (!$happy) {
            my_die("Discarding request from disallowed IP address (" .
            $_SERVER["HTTP_X_REAL_IP"] . ")\n");
        }
    }
}

function check_for_non_empty_payload()
{
    # Ensure we got a non-empty payload
    if (!isset($_POST["payload"])) {
        my_die("Received POST request with empty payload\n");
    }
}

##############################################################################

function parse_json($json_string)
{
    # Parse the JSON
    $json = json_decode($json_string);
    if (json_last_error() != JSON_ERROR_NONE) {
        my_die("Got invalid JSON\n");
    }

    return $json;
}

function fill_opts_from_json($json)
{
    # If this is just a github ping, we can ignore it
    if (!isset($json->{"action"}) ||
        ($json->{"action"} != "synchronize" &&
         $json->{"action"} != "opened")) {
       print "Hello, Github ping!  I'm here!\n";
       exit(0);
    }

    $opts["repo"] = $json->{"repository"}->{"full_name"};

    return $opts;
}

function fill_opts_from_keys($config, $opts, $arr)
{
    # Deep copy the keys/values into the already-existing $opts
    # array
    if (is_array($arr)) {
        foreach ($arr as $k => $v) {
            $opts[$k] = $v;
        }
    }

    # Was the URL set?
    if (!isset($opts["uri"]) && isset($config["url"])) {
        $opts["uri"] = $config["url"];
    }

    return $opts;
}

##############################################################################

function open_curl($url, $config)
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_USERAGENT, $config["ci-context"]);

    # Setting CURLOPT_RETURNTRANSFER variable to 1 will force curl not to
    # print out the results of its query.  Instead, it will return the
    # results as a string return value from curl_exec() instead of the
    # usual true/false.
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

    # Set CURLOPT_FOLLOWOCATION so that if we get any redirects, curl
    # will follow them to get the content from the final location.
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);

    # Set CURLOPT_HTTPHEADER to include the authorization token, just
    # in case this is a private repo.
    $headers = array(
        "Content-type: application/json",
        "Authorization: token " . $config["auth_token"]
    );
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    return $ch;
}

function get_commits($commits_url, $config)
{
    # This webhook will have only delivered the *new* commits on this
    # PR.  We need to examine *all* the commits on this PR -- so we
    # can discard the commits that were delivered in this webhook
    # payload.  Instead, do a fetch to get all the commits on this PR
    # (note: putting the Authorization header in this request just in
    # case this is a private repo).

    $ch = open_curl($commits_url, $config);
    $output = curl_exec($ch);

    # Check to see if we got success
    if (curl_errno($ch)) {
        curl_close($ch);
        my_die("Sorry, something went wrong while trying to obtain the URL \"$commits_url\".");
    }
    curl_close($ch);

    $commits = parse_json($output);

    # Sanity check
    if (count($commits) == 0) {
        my_die("Somehow there are no commits on this PR... weird...");
    }

    return $commits;
}

function process_commits($commits_url, $json, $config, $opts, $commits)
{
    $happy = true;
    if (isset($config["ci-link-url"]) &&
        $config["ci-link-url"] != "") {
        $target_url = $config["ci-link-url"];
    }
    $debug_message = "checking URL: $commits_url\n\n";
    $final_message = "";

    $i = 0;
    foreach ($commits as $key => $value) {
        if (!isset($value->{"sha"}) ||
            !isset($value->{"commit"}) ||
            !isset($value->{"commit"}->{"message"})) {
            my_die("Somehow commit infomation is missing from Github's response...");
        }

        $sha = $value->{"sha"};
        $message = $value->{"commit"}->{"message"};
        $repo = $json->{"repository"}->{"full_name"};
        $status_url = "https://api.github.com/repos/$repo/statuses/$sha";
        $debug_message .= "examining commit index $i / sha $sha:\nstatus url: $status_url\n";

        $status = array(
            "context" => $config["ci-context"]
        );

        # Look for a Signed-off-by string in this commit
        if (preg_match("/Signed-off-by/", $message)) {
            $status["state"]       = "success";
            $status["description"] = "This commit is signed off. Yay!";
            $debug_message .= "This commit is signed off\n\n";
        } else {
            $status["state"]       = "failure";
            $status["description"] = "This commit is not signed off.";
            $status["target_url"]  = $target_url;
            $debug_message .= "This commit is NOT signed off\n\n";

            $happy = false;
        }
        $final_message = $status["description"];

        # If this is the last commit in the array (and there's more than
        # one commit in the array), override its state and description to
        # represent the entire PR (because Github shows the status of the
        # last commit as the status of the overall PR).
        if ($i == count($commits) - 1 && $i > 0) {
            if ($happy) {
                $status["state"]       = "success";
                $status["description"] = "All commits signed off. Yay!";
            } else {
                $status["state"]       = "failure";
                $status["description"] = "Some commits not signed off.";
                $status["target_url"]  = $target_url;
            }
            $final_message = $status["description"];
        }

        # Send the results back to Github for this specific commit
        $ch = open_curl($status_url, $config);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($status));
        $output = curl_exec($ch);
        curl_close($ch);

        ++$i;
    }

    debug($config, "$debug_message\n");
    printf("$final_message\n");

    # Happiness!  Exit.
    exit(0);
}

function repo_matches($full_name, $key)
{
    if ($full_name == $key) {
        return 1;
    }

    // Full wildcard
    else if ($key == "*" ||
             $key == "*/*") {
        return 1;
    }

    // Partial wildcards
    preg_match("/^(.+?)\/(.+)$/", $full_name, $name_matches);
    preg_match("/^(.+?)\/(.+)$/", $key, $key_matches);
    if ($key_matches[1] == "*" && $key_matches[2] == $name_matches[2]) {
        return 1;
    }
    else if ($key_matches[2] == "*" && $key_matches[1] == $name_matches[1]) {
        return 1;
    }

    return 0;
}

function process($json, $config, $opts, $value)
{
    $opts = fill_opts_from_keys($config, $opts, $value);

    $commits_url = $json->{"pull_request"}->{"commits_url"};
    $commits = get_commits($commits_url, $config);
    process_commits($commits_url, $json, $config, $opts, $commits);
}

##############################################################################
# Main

# Verify that this is a POST
if (!isset($_POST) || count($_POST) == 0) {
    print("Use " . $_SERVER["REQUEST_URI"] .
          " as a WebHook URL in your Github repository settings.\n");
    exit(1);
}

# Read the config
$config = fill_config();

# Sanity checks
check_for_allowed_sources($config);
check_for_non_empty_payload();

$json = parse_json($_POST["payload"]);
$opts = fill_opts_from_json($json);

# Loop over all the repos in the config; see if this incoming request
# is from one we recognize.  The keys of $config["github"] are repo names
# (e.g., "open-mpi/ompi").
$repo = $json->{"repository"}->{"full_name"};
foreach ($config["github"] as $key => $value) {
    if (repo_matches($repo, $key)) {
        process($json, $config, $opts, $value);

        # process() will not return, but be paranoid anyway
        exit(0);
    }
}

# If we get here, it means we didn't find a repo match
my_die("Sorry; $repo is not a Github repo for which I provide service");
