<?php

namespace Tests\Unit\Editor;

use App\Editor\SvgSanitizer;
use Tests\TestCase;

class SvgSanitizerTest extends TestCase
{
    public function test_it_rejects_url_loads_even_when_css_or_entities_obscure_them(): void
    {
        $unsafe = [
            '<svg><rect fill="url(http://127.0.0.1/private.png)"/></svg>',
            '<svg><rect fill="u&#114;l(http://127.0.0.1/private.png)"/></svg>',
            '<svg><rect fill="u\\72 l(http://127.0.0.1/private.png)"/></svg>',
        ];

        foreach ($unsafe as $svg) {
            $this->assertNull(SvgSanitizer::sanitize($svg));
        }

        $this->assertSame('<svg><path d="M0 0h1v1H0z"/></svg>', SvgSanitizer::sanitize('<svg><path d="M0 0h1v1H0z"/></svg>'));
    }

    /**
     * The renderer puts the SVG inline in an HTML page. The HTML parser closes
     * the SVG at an HTML-only element such as `embed`, `img` or `meta`, and
     * Chrome then loads its URL. Only SVG shape elements pass.
     */
    public function test_it_rejects_an_element_that_is_not_on_the_allow_list(): void
    {
        $unsafe = [
            '<svg xmlns="http://www.w3.org/2000/svg"><embed src="http://10.0.0.5:8080/admin" width="900" height="600"/></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg"><img src="http://169.254.169.254/latest/meta-data/"/></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg"><meta http-equiv="refresh" content="0;url=http://10.0.0.5/"/></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg"><image width="10" height="10"/></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg"><a><rect width="1" height="1"/></a></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg"><g><video src="http://10.0.0.5/"/></g></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg" xmlns:h="http://www.w3.org/1999/xhtml"><h:rect/></svg>',
        ];

        foreach ($unsafe as $svg) {
            $this->assertNull(SvgSanitizer::sanitize($svg), $svg);
        }
    }

    public function test_it_rejects_an_attribute_that_is_not_on_the_allow_list(): void
    {
        $unsafe = [
            '<svg xmlns="http://www.w3.org/2000/svg"><rect src="http://10.0.0.5/" width="1" height="1"/></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg"><rect formaction="http://10.0.0.5/"/></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg" xmlns:x="urn:x"><rect x:src="http://10.0.0.5/"/></svg>',
        ];

        foreach ($unsafe as $svg) {
            $this->assertNull(SvgSanitizer::sanitize($svg), $svg);
        }
    }

    /**
     * The HTML parser ends a processing instruction or a comment at the first
     * `>`, and XML does not. The rest of the node then becomes HTML.
     */
    public function test_it_rejects_a_processing_instruction_a_comment_or_cdata(): void
    {
        $unsafe = [
            '<svg xmlns="http://www.w3.org/2000/svg"><?x > <img src="http://10.0.0.5/"> ?><rect/></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg"><!-- x --><rect/></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg"><text><![CDATA[x]]></text></svg>',
        ];

        foreach ($unsafe as $svg) {
            $this->assertNull(SvgSanitizer::sanitize($svg), $svg);
        }
    }

    public function test_it_keeps_ordinary_shapes(): void
    {
        $safe = [
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><path d="M0 0h10v10H0z" fill="#e11d48"/></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"><g transform="rotate(45 10 10)"><rect x="2" y="2" width="16" height="16" rx="2" stroke="#000" stroke-width="2" fill="none"/></g></svg>',
            '<svg viewBox="0 0 10 10"><circle cx="5" cy="5" r="4"/><ellipse cx="5" cy="5" rx="4" ry="2"/><line x1="0" y1="0" x2="10" y2="10"/><polygon points="0,0 10,0 5,10"/><polyline points="0,0 5,5"/></svg>',
            '<svg viewBox="0 0 10 10"><text x="1" y="8" font-size="6" text-anchor="start">Hi<tspan dx="1">!</tspan></text></svg>',
        ];

        foreach ($safe as $svg) {
            $this->assertSame($svg, SvgSanitizer::sanitize($svg), $svg);
        }
    }
}
