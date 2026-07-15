<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\web;

use Craft;
use craft\controllers\UsersController;
use craft\events\DefineEditUserScreensEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\services\Utilities;
use craft\web\UrlManager;
use craft\web\User as CpUser;
use stimmt\craft\Mcp\utilities\Tokens;
use yii\base\Event;

/**
 * Wires the control panel surface for MCP token management: the edit-user
 * screen, CP routes, the two gating permissions, and the utility slot.
 * Registered only from Mcp::init(), and only when the request is a CP
 * request with httpTransport enabled: no dead UI when the transport is off.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Cp {
    public static function register(): void {
        self::registerEditUserScreen();
        self::registerUrlRules();
        self::registerPermissions();
        self::registerUtility();
    }

    private static function registerEditUserScreen(): void {
        Event::on(
            UsersController::class,
            UsersController::EVENT_DEFINE_EDIT_SCREENS,
            static function (DefineEditUserScreensEvent $event): void {
                if (!self::canViewTokenScreen($event)) {
                    return;
                }

                $event->screens['mcp-tokens'] = ['label' => Craft::t('mcp', 'MCP Tokens')];
            },
        );
    }

    private static function canViewTokenScreen(DefineEditUserScreensEvent $event): bool {
        $user = Craft::$app->getUser();
        if (!$user instanceof CpUser) {
            return false;
        }

        if ($user->checkPermission('manageAllMcpTokens')) {
            return true;
        }

        return $event->editedUser->getIsCurrent() && $user->checkPermission('manageOwnMcpTokens');
    }

    private static function registerUrlRules(): void {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function (RegisterUrlRulesEvent $event): void {
                $event->rules['myaccount/mcp-tokens'] = 'mcp/cp-tokens/index';
                $event->rules['users/<userId:\d+>/mcp-tokens'] = 'mcp/cp-tokens/index';
            },
        );
    }

    private static function registerPermissions(): void {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function (RegisterUserPermissionsEvent $event): void {
                $event->permissions[] = [
                    'heading' => Craft::t('mcp', 'Craft MCP'),
                    'permissions' => [
                        'manageOwnMcpTokens' => ['label' => Craft::t('mcp', 'Manage their own MCP tokens')],
                        'manageAllMcpTokens' => ['label' => Craft::t('mcp', "Manage all users' MCP tokens")],
                    ],
                ];
            },
        );
    }

    private static function registerUtility(): void {
        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITIES,
            static function (RegisterComponentTypesEvent $event): void {
                $event->types[] = Tokens::class;
            },
        );
    }
}
