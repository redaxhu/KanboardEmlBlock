<?php

namespace Kanboard\Plugin\EmlBlock\Helper;

use Kanboard\Plugin\EmlBlock\Lib\EmlMarkdown;

// Inherit from MarkdownPlus' helper when it is installed (keeps checkboxes,
// emoji and ParsedownExtra), otherwise from the core TextHelper.
if (class_exists('Kanboard\Plugin\MarkdownPlus\Helper\MarkdownPlusHelper')) {
    class_alias('Kanboard\Plugin\MarkdownPlus\Helper\MarkdownPlusHelper', __NAMESPACE__.'\ParentTextHelper');
} else {
    class_alias('Kanboard\Helper\TextHelper', __NAMESPACE__.'\ParentTextHelper');
}

class EmlTextHelper extends ParentTextHelper
{
    public function markdown($text, $isPublicLink = false)
    {
        return parent::markdown(EmlMarkdown::expandFences((string) $text), $isPublicLink);
    }
}
