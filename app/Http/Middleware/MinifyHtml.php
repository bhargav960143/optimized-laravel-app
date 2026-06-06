<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MinifyHtml
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! str_contains($response->headers->get('Content-Type', ''), 'text/html')) {
            return $response;
        }

        $content = $response->getContent();
        if ($content === false || strlen($content) < 100) {
            return $response;
        }

        $content = $this->minify($content);
        $response->setContent($content);

        return $response;
    }

    private function minify(string $html): string
    {
        // Preserve <pre>, <script>, <style>, <textarea> blocks verbatim
        $preserved = [];
        $placeholder = "\x00PRESERVE_%d\x00";

        $html = preg_replace_callback(
            '#(<(?:pre|script|style|textarea)[^>]*>)(.*?)(</(?:pre|script|style|textarea)>)#si',
            function ($m) use (&$preserved, $placeholder) {
                $key = count($preserved);
                $preserved[$key] = $m[0];
                return sprintf($placeholder, $key);
            },
            $html
        );

        // Remove HTML comments (keep IE conditionals: <!--[if and <!–>)
        $html = preg_replace('/<!--(?!\[if).*?-->/s', '', $html);

        // Collapse whitespace between tags
        $html = preg_replace('/>\s+</s', '><', $html);

        // Collapse multiple spaces/newlines to single space
        $html = preg_replace('/\s{2,}/s', ' ', $html);

        // Restore preserved blocks
        foreach ($preserved as $key => $block) {
            $html = str_replace(sprintf($placeholder, $key), $block, $html);
        }

        return trim($html);
    }
}
