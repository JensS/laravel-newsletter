<?php

namespace JensS\Newsletter\Tests\Unit;

use JensS\Newsletter\Support\HtmlToText;
use JensS\Newsletter\Support\LinkToken;
use JensS\Newsletter\Support\Personalizer;
use JensS\Newsletter\Tests\TestCase;
use JensS\Newsletter\Webhooks\GenericSignature;
use JensS\Newsletter\Webhooks\SvixSignature;

class SupportTest extends TestCase
{
    public function test_personalizer_escapes_html_and_supports_fallbacks(): void
    {
        $values = ['name' => '<b>Ann</b>', 'city' => null];

        $this->assertSame('Hi &lt;b&gt;Ann&lt;/b&gt; from Berlin', Personalizer::html('Hi {{ name }} from {{city|Berlin}}', $values));
        $this->assertSame('Hi <b>Ann</b>', Personalizer::text('Hi {{name}}', $values));
        $this->assertSame('Hi ', Personalizer::text('Hi {{ unknown }}', $values));
        $this->assertSame(['name', 'city'], Personalizer::tokens('{{ name }} {{city|x}} {{ name }}'));
    }

    public function test_personalizer_does_not_execute_blade(): void
    {
        $this->assertSame('@php echo 1; @endphp {!! 2 !!}', Personalizer::text('@php echo 1; @endphp {!! 2 !!}', []));
    }

    public function test_html_to_text(): void
    {
        $text = HtmlToText::convert('<style>p{}</style><h1>Title</h1><p>Read <a href="https://x.test/a?b=1&amp;c=2">this</a>.</p><ul><li>One</li><li>Two</li></ul>');

        $this->assertSame("Title\n\nRead this (https://x.test/a?b=1&c=2).\n\n- One\n- Two", $text);
    }

    public function test_link_tokens_reject_tampering_and_wrong_type(): void
    {
        $token = LinkToken::unsubscribe('newsletter', 'Ann@Example.test');
        $payload = LinkToken::parse($token, LinkToken::UNSUBSCRIBE);

        $this->assertSame('ann@example.test', $payload['e']);
        $this->assertSame('newsletter', $payload['a']);
        $this->assertNull(LinkToken::parse($token, LinkToken::PREFERENCES));

        [$body, $sig] = explode('.', $token);
        $forged = rtrim(strtr(base64_encode(json_encode(['t' => 'u', 'a' => 'newsletter', 'e' => 'victim@example.test'])), '+/', '-_'), '=');
        $this->assertNull(LinkToken::parse($forged.'.'.$sig, LinkToken::UNSUBSCRIBE));
        $this->assertNull(LinkToken::parse('garbage', LinkToken::UNSUBSCRIBE));
    }

    public function test_svix_signature(): void
    {
        $secret = 'whsec_'.base64_encode('super-secret-key');
        $body = '{"type":"email.delivered"}';
        $ts = (string) time();
        $header = 'v1,invalid '.SvixSignature::sign($secret, 'msg_1', $ts, $body);

        $this->assertTrue(SvixSignature::verify($secret, 'msg_1', $ts, $header, $body));
        $this->assertFalse(SvixSignature::verify($secret, 'msg_2', $ts, $header, $body));
        $this->assertFalse(SvixSignature::verify($secret, 'msg_1', $ts, $header, $body.' '));
        $this->assertFalse(SvixSignature::verify($secret, 'msg_1', (string) (time() - 3600), SvixSignature::sign($secret, 'msg_1', (string) (time() - 3600), $body), $body));
    }

    public function test_generic_signature(): void
    {
        $header = GenericSignature::sign('s3cret', '{"a":1}');

        $this->assertTrue(GenericSignature::verify('s3cret', $header, '{"a":1}'));
        $this->assertFalse(GenericSignature::verify('other', $header, '{"a":1}'));
        $this->assertFalse(GenericSignature::verify('s3cret', GenericSignature::sign('s3cret', '{"a":1}', time() - 1000), '{"a":1}'));
    }
}
