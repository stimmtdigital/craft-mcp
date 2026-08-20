<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use yii\base\Behavior;
use yii\console\Request as ConsoleRequest;
use yii\web\HeaderCollection;

/**
 * Answers `getHeaders()` on a console request with an empty collection.
 *
 * WHY this exists, stated plainly because it is compensating for other people's
 * bugs: Craft fires its own events during a tool call, and a plugin listening on
 * one of them may reach for the current request the way it would in a web
 * context. On stdio the current request is a `craft\console\Request`, which has
 * no `getHeaders()`, so an unguarded listener kills the tool with
 * "Calling unknown method" and the agent is told nothing useful. Craft guards
 * this itself in 31 core files with `getIsConsoleRequest()`, so a listener that
 * does not is the listener's defect, but the exposure is ours: we are what makes
 * those listeners fire on a console request.
 *
 * The answer given is the honest one rather than a fiction. A console request
 * genuinely carries no headers, so the collection is empty and a listener asking
 * for one gets null: exactly the branch it would take on a web request that did
 * not send it. `getIsConsoleRequest()` still reports true, so every guarded
 * branch in Craft behaves as it does today.
 *
 * Scope is deliberately one method. Shimming `getCookies()`, `getQueryParams()`
 * and the rest pre-emptively would be signing up to emulate `craft\web\Request`
 * forever. If a third distinct method turns up the same way, that is the signal
 * to stop shimming and instead fail with a message naming the plugin whose
 * listener assumes a web request.
 *
 * @author Max van Essen <support@stimmt.digital>
 *
 * @extends Behavior<ConsoleRequest>
 */
final class ConsoleHeaders extends Behavior {
    public const string NAME = 'mcpConsoleHeaders';

    private ?HeaderCollection $headers = null;

    /**
     * Named `getHeaders` because that is the method being compensated for:
     * `Component::__call()` looks for it across attached behaviors before it
     * throws (`yii\base\Component::308-315`).
     */
    public function getHeaders(): HeaderCollection {
        return $this->headers ??= new HeaderCollection();
    }
}
