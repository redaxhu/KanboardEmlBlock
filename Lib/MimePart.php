<?php

namespace Kanboard\Plugin\EmlBlock\Lib;

/**
 * Minimal RFC 822 / MIME parser, needs only mbstring + iconv.
 */
class MimePart
{
    public $headers = array();   // lowercase name => [raw values]
    public $body = '';
    public $type = 'text/plain';
    public $params = array();
    public $parts = array();

    public static function parse($raw, $defaultType = 'text/plain')
    {
        $p = new self();
        $raw = str_replace(array("\r\n", "\r"), "\n", $raw);

        if (substr($raw, 0, 1) === "\n") {
            $head = '';
            $p->body = (string) substr($raw, 1);
        } elseif (($pos = strpos($raw, "\n\n")) === false) {
            $head = $raw;
        } else {
            $head = substr($raw, 0, $pos);
            $p->body = (string) substr($raw, $pos + 2);
        }

        $head = preg_replace('/\n(?=[ \t])/', '', $head);   // unfolding
        foreach (explode("\n", $head) as $line) {
            if (preg_match('/^([!-9;-~]+):[ \t]*(.*)$/', $line, $m)) {
                $p->headers[strtolower($m[1])][] = $m[2];
            }
        }

        list($type, $p->params) = self::parseHeaderValue($p->header('content-type'));
        $p->type = strtolower($type) ?: $defaultType;

        if (strpos($p->type, 'multipart/') === 0 && ! empty($p->params['boundary'])) {
            $sub = $p->type === 'multipart/digest' ? 'message/rfc822' : 'text/plain';
            foreach (self::splitMultipart($p->body, $p->params['boundary']) as $chunk) {
                $p->parts[] = self::parse($chunk, $sub);
            }
        }

        return $p;
    }

    public function header($name)
    {
        $name = strtolower($name);
        return isset($this->headers[$name]) ? $this->headers[$name][0] : '';
    }

    public function decodedHeader($name)
    {
        return trim(self::decodeWords($this->header($name)));
    }

    /** Raw bytes decoded according to Content-Transfer-Encoding */
    public function content()
    {
        $cte = strtolower(trim($this->header('content-transfer-encoding')));
        if ($cte === 'base64') {
            return (string) base64_decode(preg_replace('/\s+/', '', $this->body));
        }
        if ($cte === 'quoted-printable') {
            return quoted_printable_decode($this->body);
        }
        return $this->body;
    }

    /** UTF-8 text, converted from the charset parameter */
    public function text()
    {
        $cs = isset($this->params['charset']) ? $this->params['charset'] : '';
        return str_replace("\r\n", "\n", self::toUtf8($this->content(), $cs));
    }

    public function filename()
    {
        list(, $dp) = self::parseHeaderValue($this->header('content-disposition'));
        $fn = isset($dp['filename']) ? $dp['filename'] : (isset($this->params['name']) ? $this->params['name'] : '');
        return trim(self::decodeWords($fn));
    }

    public function isAttachment()
    {
        list($disp) = self::parseHeaderValue($this->header('content-disposition'));
        return strtolower($disp) === 'attachment' || $this->filename() !== '';
    }

    /** First non-attachment part of $type, depth-first */
    public function findBody($type)
    {
        if ($this->parts) {
            foreach ($this->parts as $p) {
                if (($r = $p->findBody($type)) !== null) {
                    return $r;
                }
            }
            return null;
        }
        return ($this->type === $type && ! $this->isAttachment()) ? $this : null;
    }

    /** All leaf parts that have a filename */
    public function attachments()
    {
        if ($this->parts) {
            $out = array();
            foreach ($this->parts as $p) {
                $out = array_merge($out, $p->attachments());
            }
            return $out;
        }
        return $this->filename() !== '' ? array($this) : array();
    }

    // ------------------------------------------------------------ helpers

    private static function splitMultipart($body, $boundary)
    {
        $delim = '--'.$boundary;
        $parts = array();
        $cur = null;
        foreach (explode("\n", $body) as $line) {
            $t = rtrim($line);
            if ($t === $delim.'--') {
                break;
            }
            if ($t === $delim) {
                if ($cur !== null) {
                    $parts[] = implode("\n", $cur);
                }
                $cur = array();
                continue;
            }
            if ($cur !== null) {
                $cur[] = $line;
            }
        }
        if ($cur !== null) {
            $parts[] = implode("\n", $cur);
        }
        return $parts;
    }

    /** "text/plain; charset=utf-8; format=flowed" -> ['text/plain', [...]], RFC 2231-gyel */
    public static function parseHeaderValue($value)
    {
        $segs = self::splitParams((string) $value);
        $main = trim(array_shift($segs));
        $params = array();
        $ext = array();

        foreach ($segs as $s) {
            if (! preg_match('/^\s*([^=\s]+)\s*=\s*(.*?)\s*$/s', $s, $m)) {
                continue;
            }
            $k = strtolower($m[1]);
            $v = $m[2];
            if (strlen($v) >= 2 && $v[0] === '"' && substr($v, -1) === '"') {
                $v = preg_replace('/\\\\(.)/s', '$1', substr($v, 1, -1));
            }
            if (preg_match('/^([^*]+)\*(\d*)(\*?)$/', $k, $e)) {
                $idx = $e[2] === '' ? 0 : (int) $e[2];
                $ext[$e[1]][$idx] = array($v, $e[2] === '' || $e[3] === '*');
                continue;
            }
            $params[$k] = $v;
        }

        foreach ($ext as $name => $chunks) {
            ksort($chunks);
            $charset = null;
            $val = '';
            foreach ($chunks as $c) {
                list($cv, $enc) = $c;
                if ($enc) {
                    if ($charset === null && preg_match("/^([^']*)'[^']*'(.*)$/s", $cv, $cm)) {
                        $charset = $cm[1];
                        $cv = $cm[2];
                    }
                    $cv = rawurldecode($cv);
                }
                $val .= $cv;
            }
            $params[$name] = $charset ? self::toUtf8($val, $charset) : $val;
        }

        return array($main, $params);
    }

    private static function splitParams($v)
    {
        $out = array();
        $cur = '';
        $q = false;
        $len = strlen($v);
        for ($i = 0; $i < $len; $i++) {
            $c = $v[$i];
            if ($c === '\\' && $q && $i + 1 < $len) {
                $cur .= $c.$v[++$i];
                continue;
            }
            if ($c === '"') {
                $q = ! $q;
            }
            if ($c === ';' && ! $q) {
                $out[] = $cur;
                $cur = '';
                continue;
            }
            $cur .= $c;
        }
        $out[] = $cur;
        return $out;
    }

    /** RFC 2047 encoded words; bytes of adjacent words are joined before charset
     *  conversion, so multibyte characters split across words survive */
    public static function decodeWords($s)
    {
        $s = preg_replace('/\?=\s+=\?/', '?==?', (string) $s);
        $s = preg_replace_callback('/(?:=\?[^?]+\?[BbQq]\?[^?]*\?=)+/', function ($run) {
            preg_match_all('/=\?([^?*]+)(?:\*[^?]*)?\?([BbQq])\?([^?]*)\?=/', $run[0], $ws, PREG_SET_ORDER);
            $out = '';
            $bytes = '';
            $cs = null;
            foreach ($ws as $w) {
                $data = strtoupper($w[2]) === 'B'
                    ? (string) base64_decode($w[3])
                    : quoted_printable_decode(str_replace('_', ' ', $w[3]));
                if ($cs !== null && strcasecmp($cs, $w[1]) !== 0) {
                    $out .= MimePart::toUtf8($bytes, $cs);
                    $bytes = '';
                }
                $cs = $w[1];
                $bytes .= $data;
            }
            return $out.MimePart::toUtf8($bytes, $cs);
        }, $s);
        return self::toUtf8($s, '');
    }

    public static function toUtf8($s, $charset)
    {
        $s = (string) $s;
        $cs = strtolower(trim((string) $charset, " \t\"'"));
        if ($s === '') {
            return '';
        }
        if (in_array($cs, array('', 'utf-8', 'utf8', 'us-ascii', 'ascii'), true)) {
            return self::fallback($s);
        }
        $aliases = array('iso-8859-8-i' => 'iso-8859-8', 'ks_c_5601-1987' => 'cp949');
        if (isset($aliases[$cs])) {
            $cs = $aliases[$cs];
        }
        try {
            $r = @mb_convert_encoding($s, 'UTF-8', $cs);   // PHP 8: ValueError if unknown
            if (is_string($r) && $r !== '') {
                return $r;
            }
        } catch (\Throwable $e) {
        }
        $r = @iconv($cs, 'UTF-8', $s);                       // e.g. windows-1250 (unknown to mbstring)
        if (is_string($r) && $r !== '') {
            return $r;
        }
        return self::fallback($s);
    }

    private static function fallback($s)
    {
        if (mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }
        $r = @iconv('windows-1250', 'UTF-8', $s);             // raw 8-bit headers from Central European clients
        return is_string($r) && $r !== '' ? $r : mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }
}
