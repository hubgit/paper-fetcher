<?php

class Util {
  // convert a relative URL to an absolute URL
  public static function absoluteURL($doc, $relativeURL, $htmlURL) {
    /* return if already absolute URL */
    if (parse_url($relativeURL, PHP_URL_SCHEME)) {
      return $relativeURL;
    }

    $baseURL = self::baseFromHTML($doc, $htmlURL);

    printf("Base URL: %s\n", $baseURL);

    return self::rel2abs($relativeURL, $baseURL);
  }

  // read a base URL from a HTML document
  public static function baseFromHTML($doc, $htmlURL) {
    $xpath = new DOMXPath($doc);
    $xpath->registerNamespace('xhtml', 'http://www.w3.org/1999/xhtml');

    if ($url = $xpath->evaluate('string(//head/base/@href)')) {
      return $url;
    }

    if ($url = $xpath->evaluate('string(//xhtml:head/xhtml:base/@href)')) {
      return $url;
    }

    return $htmlURL;
  }

  // http://stackoverflow.com/a/4444490/145899
  public static function rel2abs($rel, $base){
    /* return if already absolute URL */
    if (parse_url($rel, PHP_URL_SCHEME)) return $rel;

    /* remove existing fragments and query strings from the base URL */
    $base = preg_replace('/[#\?].*/', '', $base);

    /* queries and anchors */
    if ($rel[0] == '#' || $rel[0] == '?') return $base . $rel;

    /* parse base URL */
    $parts = parse_url($base);

    /* remove non-directory element from path */
    $path = preg_replace('#/[^/]*$#', '', $parts['path']);

    /* destroy path if relative url points to root */
    if ($rel[0] == '/') $path = '';

    /* dirty absolute URL */
    $abs = $parts['host'] . $path . '/' . $rel;

    /* replace '//' or '/./' or '/foo/../' with '/' */
    $re = array('#(/\.?/)#', '#/(?!\.\.)[^/]+/\.\./#');
    for($n = 1; $n > 0; $abs = preg_replace($re, '/', $abs, -1, $n)) {}

    /* absolute URL is ready! */
    return $parts['scheme'] . '://' . $abs;
  }

  // detect the content type from a Content-Type response header
  public static function detectContentType($contentType, $content = null) {
    if (preg_match('/html/i', $contentType)) {
      return 'html';
    }

    if (preg_match('/pdf/i', $contentType)) {
      return 'pdf';
    }

    if (preg_match('/json/i', $contentType)) {
      return 'json';
    }

    if (preg_match('/octet-stream/i', $contentType)) {
      if (!is_null($content)) {
        // try to detect the mime type from the content
        $finfo = finfo_open(FILEINFO_MIME);
        $mimeType = finfo_buffer($finfo, $content, FILEINFO_MIME);

        if ($mimeType && $mimeType != $contentType) {
          return self::detectContentType($mimeType);
        }
      }
    }
  }
}