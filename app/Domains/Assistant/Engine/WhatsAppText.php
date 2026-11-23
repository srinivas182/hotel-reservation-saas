<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Engine;

/**
 * Makes a reply read well in WhatsApp (0.36.4): WhatsApp shows *bold* with
 * single asterisks and has no headings or Markdown lists, so what an AI
 * writes in Markdown is turned into WhatsApp's own formatting, and the
 * spacing is kept to short paragraphs.
 */
final class WhatsAppText
{
    public static function tidy(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // **bold** or __bold__ → *bold*
        $text = (string) preg_replace('/\*\*(.+?)\*\*/u', '*$1*', $text);
        $text = (string) preg_replace('/__(.+?)__/u', '*$1*', $text);

        $lines = [];
        foreach (explode("\n", $text) as $line) {
            $line = rtrim($line);

            // "### Title" → "*Title*"
            if (preg_match('/^\s*#{1,6}\s+(.+)$/u', $line, $m) === 1) {
                $line = '*'.trim($m[1], '* ').'*';
            }

            // "- item", "* item", "• item" → "• item"
            $line = (string) preg_replace('/^\s*(?:[-*•·])\s+/u', '• ', $line);

            $lines[] = $line;
        }

        $text = implode("\n", $lines);

        // At most one empty line between paragraphs.
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }
}
