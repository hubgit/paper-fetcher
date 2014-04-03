# Paper Fetcher

Given a list of DOIs, one per line in a file named `dois.txt`, `php fetch.php` will read each DOI, attempt to fetch bibliographic JSON from dx.doi.org and HTML from the publisher via dx.doi.org, then parse the HTML to find a PDF URL and fetch that PDF.

If successful, the files are stored in the data folder.

Alongside each file a JSON file is stored that contains metadata for the HTTP request/response.

The XPath selectors to find the PDF URL (or next HTML URL, if it's an interstitial page) are in the `findPdfUrl` method of `Article.php`. They work for most publishers, but not all - it will almost certainly be necessary to add domain-specific rules to get 100% coverage.

Note that if page content is generated dynamically or fetched with JavaScript, it won't be captured.
