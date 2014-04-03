<?php

// an article
class Article {
  protected $curl;
  protected $log;
  protected $doi;
  protected $base;

  // construct
  public function __construct($curl, $log, $doi) {
    $this->curl = $curl;
    $this->log = $log;
    $this->doi = trim($doi);
    $this->base = base64_encode($doi);

    print_r(array(
      'doi' => $this->doi,
      'base' => $this->base,
    ));
  }

  // write an array of data to the log file as CSV
  public function log($data) {
    $data[] = $this->doi;
    $data[] = $this->base;

    fputcsv($this->log, $data);
  }

  // generate a file path for a data format
  protected function path($format) {
    return sprintf('data/%s/%s.%s', $format, $this->base, $format);
  }

  // load a JSON file
  protected function loadJSON($file) {
    return json_decode(file_get_contents($file . '.json'), true);
  }

  // save a JSON file to accompany the data file
  public function saveJSON($file, $info) {
    print_r($info);
    file_put_contents($file . '.json', json_encode($info, JSON_PRETTY_PRINT));
  }

  // check whether the file and description are present
  public function fetched($file) {
    return file_exists($file) && file_exists($file . '.json');
  }

  // fetch each of the data formats for this item
  public function fill() {
    // fetch JSON
    $this->fetchJSON();

    if (!file_exists($this->path('json'))) {
      $this->log(array('No JSON'));
      //return; // Bad DOI
    }

    // fetch HTML
    $this->fetchHTML();

    if (!file_exists($this->path('html'))) {
      $this->log(array('No HTML'));
      return;
    }

    // parse HTML to find PDF URL
    $doc = new DOMDocument;
    $doc->loadHTMLFile($this->path('html'));

    $pdfURL = $this->findPdfUrl($doc);

    if (!$pdfURL) {
      $this->log(array('No PDF URL'));
      return;
    }

    // convert relative PDF URL to absolute URL
    $info = $this->loadJSON($this->path('html'));
    $pdfURL = $this->absoluteURL($doc, $pdfURL, $info['url']);
    printf("PDF URL: %s\n", $pdfURL);

    // fetch PDF
    $this->fetchPDF($pdfURL);

    if (!file_exists($this->path('pdf'))) {
      $this->log(array('No PDF'));
      return;
    }
  }

  // fetch citeproc JSON via dx.doi.org (data.crossref.org)
  public function fetchJSON() {
    $file = $this->path('json');

    if ($this->fetched($file)) {
      return;
    }

    printf("Fetching CiteProc JSON\n");
    curl_setopt($this->curl, CURLOPT_HTTPHEADER, array('Accept: application/citeproc+json'));
    curl_setopt($this->curl, CURLOPT_URL, 'http://dx.doi.org/' . rawurlencode($this->doi));
    $result = curl_exec($this->curl);
    $info = curl_getinfo($this->curl);
    $this->saveJSON($file, $info);

    if ($info['http_code'] != 200) {
      return;
    }

    switch ($this->detectContentType($info['content_type'])) {
      case 'json':
        // pretty print the JSON
        $result = json_encode(json_decode($result, true), JSON_PRETTY_PRINT);
        file_put_contents($file, $result);
        break;
    }
  }

  // fetch the HTML resource via dx.doi.org
  public function fetchHTML() {
    $file = $this->path('html');

    if ($this->fetched($file)) {
      return;
    }

    printf("Fetching HTML\n");
    curl_setopt($this->curl, CURLOPT_HTTPHEADER, array(
      'Accept: text/html',
      'Cookie: WKCookieName=lww' // Wolters Kluwer Health gateway selection
      // TODO: add sciencedirect interstitial cookie?
    ));
    curl_setopt($this->curl, CURLOPT_URL, 'http://dx.doi.org/' . rawurlencode($this->doi));
    $result = curl_exec($this->curl);
    $info = curl_getinfo($this->curl);
    $this->saveJSON($file, $info);

    if ($info['http_code'] != 200) {
      return;
    }

    switch ($this->detectContentType($info['content_type'])) {
      case 'html':
      file_put_contents($file, $result);
      break;
    }
  }

  // fetch a PDF file
  // if HTML is returned, look for a different PDF URL and try that
  public function fetchPDF($url) {
    $file = $this->path('pdf');

    if ($this->fetched($file)) {
      return;
    }

    printf("Fetching PDF\n");
    curl_setopt($this->curl, CURLOPT_HTTPHEADER, array('Accept: application/pdf'));
    curl_setopt($this->curl, CURLOPT_URL, $url);
    $result = curl_exec($this->curl);
    $info = curl_getinfo($this->curl);
    $this->saveJSON($file, $info);

    if ($info['http_code'] != 200) {
      return;
    }

    switch ($this->detectContentType($info['content_type'])) {
      // PDF response
      case 'pdf':
      file_put_contents($file, $result);
      break;

      // HTML response - look for a new PDF URL
      case 'html':
        // NOTE: not saving the new HTML file, as an access page might overwrite an interstitial page

        $doc = new DOMDocument;
        $doc->loadHTML($result);

        if ($pdfURL = $this->findPdfUrl($doc)){
          print_r(array($pdfURL, $info['url']));
          $pdfURL = $this->absoluteURL($doc, $pdfURL, $info['url']);
          printf("Next PDF URL: %s\n", $pdfURL);

          if ($pdfURL && $pdfURL != $url) {
            $this->fetchPDF($pdfURL);
          }
        };
        break;
    }
  }

  // detect the content type from a Content-Type response header
  protected function detectContentType($contentType) {
    if (preg_match('/html/i', $contentType)) {
      return 'html';
    }

    if (preg_match('/pdf/i', $contentType)) {
      return 'pdf';
    }

    if (preg_match('/json/i', $contentType)) {
      return 'json';
    }
  }

  // find the URL of a PDF file in a HTML file
  protected function findPdfUrl($doc) {
    $xpath = new DOMXPath($doc);
    $xpath->registerNamespace('xhtml', 'http://www.w3.org/1999/xhtml');

    // citation_pdf_url
    if ($url = $xpath->evaluate('string(//meta[@name="citation_pdf_url"]/@content)')) {
      return $url;
    }

    // xhtml:citation_pdf_url
    if ($url = $xpath->evaluate('string(//xhtml:meta[@name="citation_pdf_url"]/@content)')) {
      return $url;
    }

    // wkhealth_pdf_url
    if ($url = $xpath->evaluate('string(//meta[@name="wkhealth_pdf_url"]/@content)')) {
      return $url;
    }

    // sciencedirect.com
    if ($url = $xpath->evaluate('string(//a/@pdfurl)')) {
      return $url;
    }

    // sciencedirect.com interstitial
    if ($url = $xpath->evaluate('string(//form[@name="articleSelect"]//input[@name="siteKey[\'sd\']"]/@value)')) {
      return $url; // HTML URL
    }

    // pt.wkhealth.com interstitial
    if ($url = $xpath->evaluate('string(//form[@name="articleSelect"]//input[@name="siteKey[\'sd\']"]/@value)')) {
      return $url; // HTML URL
    }

    // journals.cambridge.org interstitial
    // TODO: "a" node name selector?
    if ($url = $xpath->evaluate('string(//img[@title="Download PDF"]/../@href)')) {
      return $url; // HTML URL
    }

    // nature.com
    if ($url = $xpath->evaluate('string(//a[text()="Download PDF"]/@href)')) {
      return $url;
    }

    // tandfonline.com
    if ($url = $xpath->evaluate('string(//a[@class="pdf"][text()="Download full text"]/@href)')) {
      return $url;
    }

    // pubs.acs.org
    if ($url = $xpath->evaluate('string(//a[text()="PDF"]/@href)')) {
      return $url;
    }

    // informahealthcare.com
    if ($url = $xpath->evaluate('string(//a[@class="pdflink"][contains(text(), " PDF ")]/@href)')) {
      return $url;
    }

    return null;
  }

  // convert a relative URL to an absolute URL
  protected function absoluteURL($doc, $url, $baseURL) {
    if (preg_match('/^https?:\/\//', $url)) {
      return $url;
    }

    if ($tmp = $this->baseURL($doc)) {
      $baseURL = $tmp;
    };

    printf("Base URL: %s\n", $baseURL);

    return $this->rel2abs($url, $baseURL);
  }

  // read a base URL from a HTML document
  protected function baseURL($doc) {
    $xpath = new DOMXPath($doc);
    $xpath->registerNamespace('xhtml', 'http://www.w3.org/1999/xhtml');

    if ($url = $xpath->evaluate('string(//head/base/@href)')) {
      return $url;
    }

    if ($url = $xpath->evaluate('string(//xhtml:head/xhtml:base/@href)')) {
      return $url;
    }

    return null;
  }

  // http://stackoverflow.com/a/4444490/145899
  protected function rel2abs($rel, $base){
    /* return if already absolute URL */
    if (parse_url($rel, PHP_URL_SCHEME) != '') return $rel;

    /* queries and anchors */
    if ($rel[0] == '#' || $rel[0] == '?') return $base . $rel;

    /* parse base URL and convert to local variables: $scheme, $host, $path */
    extract(parse_url($base));

    /* remove non-directory element from path */
    $path = preg_replace('#/[^/]*$#', '', $path);

    /* destroy path if relative url points to root */
    if ($rel[0] == '/') $path = '';

    /* dirty absolute URL */
    $abs = $host . $path . '/' . $rel;

    /* replace '//' or '/./' or '/foo/../' with '/' */
    $re = array('#(/\.?/)#', '#/(?!\.\.)[^/]+/\.\./#');
    for($n = 1; $n > 0; $abs = preg_replace($re, '/', $abs, -1, $n)) {}

    /* absolute URL is ready! */
    return $scheme . '://' . $abs;
  }
}