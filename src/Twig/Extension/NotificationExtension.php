<?php

declare(strict_types=1);

namespace App\Twig\Extension;

use App\Twig\Runtime\NotificationRuntime;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class NotificationExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('navbar_notifications', [NotificationRuntime::class, 'getNavbarNotifications']),
            new TwigFunction('navbar_notifications_count', [NotificationRuntime::class, 'getNavbarNotificationsCount']),
        ];
    }
}
