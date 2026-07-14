<?php
declare(strict_types=1);

namespace Tds\Ext\SupportTickets\Tests;

use PHPUnit\Framework\TestCase;
use Tds\Ext\SupportTickets\Service\ImapTicketIngest;

/**
 * The pure email-parsing helpers, unit-tested without a live mailbox (webklex is
 * only touched by connect(), never by these static methods).
 */
final class ImapParsingTest extends TestCase
{
    public function testParseTicketIdFromSubject(): void
    {
        self::assertSame(42, ImapTicketIngest::parseTicketIdFromSubject('Re: Problem [Ticket #42]'));
        self::assertNull(ImapTicketIngest::parseTicketIdFromSubject('Kein Marker hier'));
    }

    public function testNormalizeMessageId(): void
    {
        self::assertSame('abc@host', ImapTicketIngest::normalizeMessageId('<abc@host>'));
        self::assertSame('abc@host', ImapTicketIngest::normalizeMessageId('  abc@host '));
    }

    public function testExtractMessageIds(): void
    {
        self::assertSame(
            ['a@h', 'b@h'],
            ImapTicketIngest::extractMessageIds('<a@h> <b@h> <a@h>'),
        );
        self::assertSame(['bare@h'], ImapTicketIngest::extractMessageIds('bare@h'));
        self::assertSame([], ImapTicketIngest::extractMessageIds('   '));
    }

    public function testStripQuotedReplyCutsHistory(): void
    {
        $body = "Neue Antwort.\nAm 1.1.2026 schrieb Support <s@x>:\n> alte Nachricht";
        self::assertSame('Neue Antwort.', ImapTicketIngest::stripQuotedReply($body));
    }

    public function testCleanSubjectStripsPrefixesAndFallsBack(): void
    {
        self::assertSame('Problem', ImapTicketIngest::cleanSubject('Re: AW: Problem'));
        self::assertSame('E-Mail-Anfrage', ImapTicketIngest::cleanSubject('  Re:  '));
    }

    public function testHtmlToText(): void
    {
        $text = ImapTicketIngest::htmlToText('<p>Hallo</p><p>Welt</p><script>x()</script>');
        self::assertStringContainsString('Hallo', $text);
        self::assertStringContainsString('Welt', $text);
        self::assertStringNotContainsString('x()', $text);
    }
}
