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
# This file must contain the function fill_config().
##############################################################################
##############################################################################

function fill_config()
{
    # verify-signed-off.php will ignore any incoming POST that does
    # not come from these network blocks.  As of 9 Aug 2016, according
    # to
    # https://help.github.com/articles/what-ip-addresses-does-github-use-that-i-should-whitelist/:
    $config["allowed_sources"] = array("192.30.252.0/22");

    # Fill this in with your Github personal access token.
    $config["auth_token"] = "Fill me in";

    # The name of the CI agent at Github
    $config["ci-context"] = "Signed-off-by";

    # Optional: if a commit is not signed off, link to this URL
    $config["ci-link-url"] = "http://ltsi.linuxfoundation.org/developers/signed-process";

    # You almost certainly want "debug" set to 0 (or unset)
    $config["debug"] = 0;

    # These are the allowed repos that we reply to
    $config["github"]["jsquyres/signed-off-by-github-webhook"] = 1;

    # Return the $config variable
    return $config;
}
