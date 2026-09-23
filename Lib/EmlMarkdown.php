<?php

namespace Kanboard\Plugin\EmlBlock\Lib;

/**
 * PHP port of eml2md.py's formatting, plus expansion of ```eml fenced blocks.
 *
 * Info-string options:  ```eml noquote   (drop the quoted history)
 *                       ```eml sig       (keep the first line of the signature)
 */
class EmlMarkdown
{
    private static $attribution = array(
        '/^On .{5,200} wrote:\s*$/u',
        '/^.{5,200} írta:\s*$/u',
        '/^-{3,}\s*Original Message\s*-{3,}/iu',
        '/^-{3,}\s*Eredeti üzenet\s*-{3,}/iu',
        '/^_{10,}\s*$/',                                   // Outlook separator
    );

    // ------------------------------------------------ fence expansion

    public static function expandFences($text)
    {
        if (stripos($text, 'eml') === false) {
            return $text;
        }

        $lines = explode("\n", str_replace("\r\n", "\n", $text));
        $n = count($lines);
        $out = array();

        for ($i = 0; $i < $n; $i++) {
            if (! preg_match('/^ {0,3}(`{3,}|~{3,})(.*)$/', $lines[$i], $m)) {
                $out[] = $lines[$i];
                continue;
            }

            $fence = $m[1];
            $close = '/^ {0,3}'.preg_quote($fence[0], '/').'{'.strlen($fence).',}\s*$/';
            for ($j = $i + 1; $j < $n && ! preg_match($close, $lines[$j]); $j++) {
            }

            $block = array_slice($lines, $i, $j - $i + 1);        // opening..closing fence (or to EOF)
            $md = null;

            if ($j < $n && preg_match('/^\s*eml(?:\s+(.*))?$/i', $m[2], $info)) {
                $opts = isset($info[1]) ? preg_split('/\s+/', strtolower(trim($info[1])), -1, PREG_SPLIT_NO_EMPTY) : array();
                try {
                    $md = self::render(implode("\n", array_slice($lines, $i + 1, $j - $i - 1)), $opts);
                } catch (\Throwable $e) {
                    $md = null;                                   // on error keep the code block
                }
            }

            if ($md === null) {
                $out = array_merge($out, $block);                 // other languages / failed blocks untouched
            } else {
                $out[] = '';
                $out[] = $md;
                $out[] = '';
            }
            $i = $j;
        }

        return implode("\n", $out);
    }

    public static function render($raw, array $opts = array())
    {
        $msg = MimePart::parse(ltrim($raw, "\r\n"));
        if ($msg->header('from') === '' && $msg->header('subject') === '') {
            return null;                                          // not an e-mail
        }
        return self::build($msg, ! in_array('noquote', $opts, true), in_array('sig', $opts, true));
    }

    // ------------------------------------------------ body extraction

    public static function getBody(MimePart $msg)
    {
        $p = $msg->findBody('text/plain');
        if ($p !== null) {
            $t = $p->text();
            $fmt = isset($p->params['format']) ? strtolower($p->params['format']) : '';
            if ($fmt === 'flowed') {
                $delsp = isset($p->params['delsp']) && strtolower($p->params['delsp']) === 'yes';
                $t = self::unflow($t, $delsp);
            }
            return $t;
        }
        $p = $msg->findBody('text/html');
        return $p !== null ? self::htmlToText($p->text()) : '';
    }

    /** Undo RFC 3676 format=flowed */
    public static function unflow($text, $delsp = false)
    {
        $out = array();
        $buf = null;
        $bufDepth = 0;

        foreach (explode("\n", $text) as $raw) {
            $raw = rtrim($raw, "\r");
            preg_match('/^>*/', $raw, $m);
            $depth = strlen($m[0]);
            $line = (string) substr($raw, $depth);
            if (substr($line, 0, 1) === ' ') {
                $line = (string) substr($line, 1);                // space-stuffing
            }
            $soft = substr($line, -1) === ' ' && $line !== '-- ';
            if ($soft && $delsp) {
                $line = (string) substr($line, 0, -1);
            }
            if ($buf !== null && $depth === $bufDepth) {
                $buf .= $line;
            } else {
                if ($buf !== null) {
                    $out[] = array($bufDepth, $buf);
                }
                $buf = $line;
                $bufDepth = $depth;
            }
            if (! $soft) {
                $out[] = array($bufDepth, $buf);
                $buf = null;
            }
        }
        if ($buf !== null) {
            $out[] = array($bufDepth, $buf);
        }

        return implode("\n", array_map(function ($x) {
            return ($x[0] ? str_repeat('>', $x[0]).' ' : '').$x[1];
        }, $out));
    }

    /** Minimal HTML -> Markdown, used only when there is no text/plain part */
    public static function htmlToText($html)
    {
        $h = preg_replace('#<(style|script|head|title)\b.*?</\1\s*>#is', '', $html);
        $h = preg_replace('#<br\s*/?>#i', "\n", $h);
        $h = preg_replace('#</?(p|div|tr|table|pre|blockquote|h[1-6])\b[^>]*>#i', "\n", $h);
        $h = preg_replace('#<li\b[^>]*>#i', "\n- ", $h);
        $h = preg_replace('#</?(b|strong)\b[^>]*>#i', '**', $h);
        $h = preg_replace('#</?(i|em)\b[^>]*>#i', '_', $h);
        $h = preg_replace_callback('#<a\b[^>]*\bhref\s*=\s*["\']([^"\']*)["\'][^>]*>(.*?)</a\s*>#is', function ($m) {
            $label = trim(strip_tags($m[2]));
            return $label === '' || $label === $m[1] ? $m[1] : '['.$label.']('.$m[1].')';
        }, $h);
        $h = strip_tags($h);
        $h = html_entity_decode($h, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $h = str_replace("\xc2\xa0", ' ', $h);
        $h = preg_replace("/[ \t]+\n/", "\n", $h);
        $h = preg_replace("/\n[ \t]+/", "\n", $h);
        return trim(preg_replace("/\n{3,}/", "\n\n", $h));
    }

    // ------------------------------------------------ own text / quote

    public static function splitBody($text)
    {
        $lines = explode("\n", $text);

        $cut = null;
        foreach ($lines as $i => $l) {
            if (substr($l, 0, 1) === '>') {
                $cut = $i;
                break;
            }
            foreach (self::$attribution as $re) {
                if (preg_match($re, $l)) {
                    $cut = $i;
                    break 2;
                }
            }
        }
        $own = $cut === null ? $lines : array_slice($lines, 0, $cut);
        $quote = $cut === null ? array() : array_slice($lines, $cut);

        // look for the signature only in the own part, so with top-posting it
        // does not swallow the quoted history below it
        $sig = '';
        for ($i = count($own) - 1; $i >= 0; $i--) {
            if ($own[$i] === '-- ') {
                $sig = trim(implode("\n", array_slice($own, $i + 1)));
                $own = array_slice($own, 0, $i);
                break;
            }
        }

        return array(trim(implode("\n", $own)), trim(implode("\n", $quote)), $sig);
    }

    // ------------------------------------------------------------ Markdown

    /** Keep "<" literal: MarkdownPlus allows raw HTML and would treat <addr@host> as a tag */
    private static function esc($s)
    {
        return str_replace('<', '&lt;', $s);
    }

    public static function mdText($text)
    {
        $out = array();
        foreach (explode("\n", $text) as $l) {
            $l = preg_replace('/^((?:>\s?)*)\*([^*\n]+)\*\s*$/u', '$1**$2**', $l);   // Thunderbird *heading*
            $t = trim($l);
            if ($t !== '' && $t !== '>') {
                $l .= '  ';                                                         // hard line break
            }
            $out[] = self::esc($l);
        }
        return trim(implode("\n", $out));
    }

    public static function mdCell($s)
    {
        return self::esc(trim(str_replace(array('|', "\n"), array('\|', ' '), (string) $s)));
    }

    public static function build(MimePart $msg, $keepQuote = true, $keepSig = false)
    {
        $subject = $msg->decodedHeader('subject');
        if ($subject === '') {
            $subject = '(nincs tárgy)';
        }

        $date = $msg->header('date');
        try {
            $dateS = $date !== '' ? (new \DateTime(preg_replace('/\s*\(.*\)\s*$/', '', $date)))->format('Y-m-d H:i O') : '';
        } catch (\Exception $e) {
            $dateS = $date;
        }

        $rows = array(
            'Feladó'     => $msg->decodedHeader('from'),
            'Címzett'    => $msg->decodedHeader('to'),
            'Másolat'    => $msg->decodedHeader('cc'),
            'Dátum'      => $dateS,
            'Message-ID' => $msg->decodedHeader('message-id'),
        );

        list($own, $quote, $sig) = self::splitBody(self::getBody($msg));

        $md = array('### ✉ '.self::mdCell($subject), '', '| | |', '|---|---|');
        foreach ($rows as $k => $v) {
            if ($v !== '') {
                $md[] = '| **'.$k.'** | '.self::mdCell($v).' |';
            }
        }

        $atts = array();
        foreach ($msg->attachments() as $a) {
            $atts[] = sprintf('%s (%s, %.1f kB)', $a->filename(), $a->type, strlen($a->content()) / 1024);
        }
        if ($atts) {
            $md[] = '| **Mellékletek** | '.self::mdCell(implode('; ', $atts)).' |';
        }

        $md[] = '';
        $md[] = '---';
        $md[] = '';
        $md[] = self::mdText($own);

        if ($keepSig && $sig !== '') {
            $first = explode("\n", $sig);
            $md[] = '';
            $md[] = '_'.self::esc($first[0]).'_';
        }
        if ($keepQuote && $quote !== '') {
            array_push($md, '', '---', '', '**Korábbi levelezés:**', '', self::mdText($quote));
        }

        return implode("\n", $md);
    }
}
