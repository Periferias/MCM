<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener\Audit;

use App\Document\UserTimeline;
use App\Entity\User;
use App\Enum\UserStatusEnum;
use App\EventListener\Audit\AuditUpdateListener;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;
use Throwable;

final class AuditUpdateListenerTest extends TestCase
{
    public function testAuditUserUpdateWithoutAuthenticatedActor(): void
    {
        $user = new User();
        $user->setId(Uuid::v4());
        $user->setFirstname('User');
        $user->setLastname('Test');
        $user->setEmail('user.test@example.com');
        $user->setPassword('new-password-hash');
        $user->setStatus(UserStatusEnum::ACTIVE->value);

        $documentManager = $this->createMock(DocumentManager::class);
        $persistedDocument = null;
        $documentManager->expects(self::once())
            ->method('persist')
            ->willReturnCallback(static function (object $document) use (&$persistedDocument): void {
                $persistedDocument = $document;
            });
        $documentManager->expects(self::once())
            ->method('flush');

        $security = $this->createMock(Security::class);
        $security->expects(self::once())
            ->method('getUser')
            ->willReturn(null);

        $listener = new AuditUpdateListener(
            $documentManager,
            new RequestStack(),
            $security,
        );

        $changeSet = ['password' => ['old-password-hash', 'new-password-hash']];
        $event = new PreUpdateEventArgs(
            $user,
            $this->createMock(EntityManagerInterface::class),
            $changeSet,
        );

        try {
            $listener($event);
        } catch (Throwable $exception) {
            self::fail(sprintf(
                'An unauthenticated user update must be audited without failing: %s',
                $exception->getMessage(),
            ));
        }

        self::assertInstanceOf(UserTimeline::class, $persistedDocument);
        self::assertNull($persistedDocument->getUserId());
        self::assertSame($user->getId()->toRfc4122(), $persistedDocument->getResourceId());
    }
}
