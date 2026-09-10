<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Backend;

/**
 * The agent's own labels, translated for the current backend user.
 */
final class Labels
{
    private const LL = 'LLL:EXT:caretaker2_agent/Resources/Private/Language/locallang.xlf:';

    /**
     * A key from the agent's locallang, with its placeholders filled in.
     *
     * @param string|int ...$arguments
     */
    public function get(string $key, ...$arguments): string
    {
        $text = (string)$GLOBALS['LANG']->sL(self::LL . $key);

        return $arguments === [] ? $text : vsprintf($text, $arguments);
    }
}
