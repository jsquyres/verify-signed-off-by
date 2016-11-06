# "Signed-off-by" Github webhook

This is a simple, pure-PHP Github webhook that checks whether all
commits contain the git "Signed-off-by" token.

If not all commits contain that token, this script will return a
failure up to Github, and your Github webhook CI test will fail.

# Why does this project exist?

This project exists as a simple, standalone PHP script so that you can
run this checker in web hosting environments where you are unable to
run additional daemons.  For example, many simple web hosting packages
allow arbitrary PHP web pages, but do not allow running standalone
processes (such as a Ruby / Sinatra process) for more than a short
period of time.

# Installation

Installation is comprised of two parts:

1. Installation on the web server
1. Installation at Github.com

## Installation on the web server

1. Copy `verify-signed-off-by.php` to a folder in a docroot somewhere.
1. Copy the sample `verify-signed-off-by-config.inc` to the same folder.
   * Edit the `verify-signed-off-by-config.inc` to reflect the
     configuration that you want.
   * You will need to set the name(s) of the Github repos for which
     you want the script to respond.
   * You will also need to set the `auth_token` value to be the
     Personal Access Token (PAT) of a user who has commit access to each of
     these repos.
     * You can generate a Github PAT by visiting
       https://github.com/settings/tokens and clicking the "Generate
       new tokens" button in the top right.
     * The only permission that this PAT needs is `repo:status`.
1. Configure your web server to deny client access to
   `verify-signed-off-by-config.inc`.
   * ***THIS IS NOT OPTIONAL***
   * Failure to do so will expose your PAT!
   * For example, you can create a `.htaccess` file in the same
     directory to restrict access to your
     `verify-signed-off-by-config.inc` containing:
```xml
<Files "verify-signed-off-by-config.inc">
    Order allow,deny
    Deny from all
    Satisfy all
</Files>
```

## Installation at Github.com

1. On Github.com, create a custom webhook for your Git repo:
   * The URL should be the URL of your newly-installed `verify-signed-off-by.php`.
   * The content type should be `application/x-www-form-urlencoded`.
   * Select "Let me select individual events."
     * Check the "Pull request" event.
     * You can choose to leave the "Push" event checked or not.
   * Make the webhook active.
1. When you create a webhook at Github.com, it should send a "ping" request to the webhook to make sure it is correct.
   * On your git repository's webhooks page, click on your webhook.
   * On the resulting page, scroll down to the "Recent Deliveries" section.  The very first delivery will be the "ping" event.
   * Click on the first delivery and check that the response code is 200.
   * If everything is working properly, the output body should say
     `Hello, Github ping!  I'm here!`.
   * If you do not see the ping results, see below.
1. Do a push to your Git repository.
   * If all goes well, all commits that are signed off should get a
     green check.
   * If any commit(s) is(are) *not* signed off, then that that(those)
     commit(s) should get a red X, and the *last* commit should also
     get a red X.
   * If you don't see the CI results on the pull request, see below.

## Troubleshooting

Common problems include:

* Not seeing the Github ping.
  * Check that the URL in your Github webhook is correct.
  * If using an `https` URL, check that the certificate is
    authenticate-able by Github (i.e., it's signed by a recognizable
    CA, etc.)
  * Try test surfing to the URL of your Github webhook yourself and
    make sure it is working properly.  You should see:
```
Use /github/verify-signed-off-by.php as a WebHook URL in your Github repository settings.
````

* Not seeing the checker's results in the CI block on the PR
  * Ensure that the PAT used is from a user that has commit access to
    the repository.  If the PHP script is running properly and the
    results don't appear in the PR CI section, *this is almost always
    the problem*.
  * Check the "Recent deliveries" section of your webhook's
    configuration page and check the response body output from your
    delivery.
  * Click on a delivery hash to expand it; click the Response tab to
    see the output from the PHP script.
  * If all goes well, the last line of output should be `This commit
    is signed off. Yay!` or `All commits signed off. Yay!`
